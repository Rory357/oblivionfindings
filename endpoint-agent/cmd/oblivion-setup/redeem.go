package main

// "redeem": the Windows installer's first call (S&D Phase B, windows tab screens 1 and 3).
//
// The download is one signed file for everyone, named for its Site with the server and a
// one-time setup code: "Oblivion-Setup (Tōtara House) [oblivionfindings.com T6WD-3KQN].exe".
// redeem reads that name (or takes the 8 characters the person typed), swaps the code for the
// setup profile over HTTPS with Windows' normal certificate checks, and writes what the
// installer needs into a private folder:
//
//	setup.ini             what the screens show, and the outcome (UTF-16, so Inno reads ō)
//	site-profile.json     the hub's profile (hub codes)
//	computer-profile.json Obli's profile (computer codes, and hub PCs per D4)
//
// It never prints or logs a profile, token or key.

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
)

// Exit codes the installer acts on. setup.ini carries the same outcome in words.
const (
	exitRedeemUsed        = 10
	exitRedeemExpired     = 11
	exitRedeemCancelled   = 12
	exitRedeemUnknown     = 13
	exitRedeemNoCode      = 14
	exitRedeemUnreachable = 20
	exitRedeemProxy       = 21
	exitRedeemServer      = 22
	exitRedeemUntrusted   = 23
)

// exitError carries an exit code to main without printing anything extra.
type exitError struct {
	code    int
	message string
}

func (e exitError) Error() string { return e.message }

// codeAlphabet is Crockford base32, as SetupCodeService.php: no I, L, O or U.
const codeAlphabet = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"

var fileNamePattern = regexp.MustCompile(`\[([A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?) ([0-9A-Za-z]{4}-?[0-9A-Za-z]{4})\]`)
var hostPattern = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::[0-9]{1,5})?$`)
var computerNamePattern = regexp.MustCompile(`^[A-Z0-9][A-Z0-9-]{0,62}$`)

// normaliseCode matches SetupCodeService::normalise: spaces and dashes ignored, lower case
// accepted, O/I/L read as 0/1/1. It returns XXXX-XXXX or "".
func normaliseCode(raw string) string {
	clean := strings.ToUpper(strings.NewReplacer(" ", "", "-", "", "\t", "").Replace(strings.TrimSpace(raw)))
	clean = strings.NewReplacer("O", "0", "I", "1", "L", "1").Replace(clean)
	if len(clean) != 8 {
		return ""
	}
	for _, r := range clean {
		if !strings.ContainsRune(codeAlphabet, r) {
			return ""
		}
	}
	return clean[:4] + "-" + clean[4:]
}

// normaliseHost accepts a plain host name with an optional port, nothing else.
func normaliseHost(raw string) string {
	host := strings.ToLower(strings.TrimSpace(raw))
	if !hostPattern.MatchString(host) || strings.HasPrefix(host, ".") {
		return ""
	}
	if _, port, err := net.SplitHostPort(host); err == nil {
		if number, err := strconv.Atoi(port); err != nil || number < 1 || number > 65535 {
			return ""
		}
	}
	return host
}

// parseInstallerName reads "[server CODE]" from the file name. A browser may add " (1)".
func parseInstallerName(name string) (server, code string) {
	match := fileNamePattern.FindStringSubmatch(filepath.Base(name))
	if match == nil {
		return "", ""
	}
	return normaliseHost(match[1]), normaliseCode(match[2])
}

// zoneServer reads the server from the download's Zone.Identifier stream, which browsers keep
// even when the file is renamed. Only the host is used; the code must still be typed.
func zoneServer(path string) string {
	file, err := os.Open(path + ":Zone.Identifier")
	if err != nil {
		return ""
	}
	defer file.Close()
	data, _ := io.ReadAll(io.LimitReader(file, 8192))
	for _, line := range strings.Split(string(data), "\n") {
		name, value, found := strings.Cut(strings.TrimSpace(line), "=")
		if !found || (!strings.EqualFold(name, "HostUrl") && !strings.EqualFold(name, "ReferrerUrl")) {
			continue
		}
		u, err := url.Parse(strings.TrimSpace(value))
		if err == nil && u.Scheme == "https" && u.Host != "" {
			if host := normaliseHost(u.Host); host != "" {
				return host
			}
		}
	}
	return ""
}

type redeemOptions struct {
	installer, server, code, computer, out, proxy, defaultServer string
}

func parseRedeemOptions(args []string) (redeemOptions, error) {
	var o redeemOptions
	flags := flag.NewFlagSet("redeem", flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	flags.StringVar(&o.installer, "installer", "", "path of this installer, whose name carries the server and code")
	flags.StringVar(&o.server, "server", "", "server host, when it isn't in the file name")
	flags.StringVar(&o.defaultServer, "default-server", "", "server host built into this installer, used last")
	flags.StringVar(&o.code, "code", "", "the 8-character setup code the person typed")
	flags.StringVar(&o.computer, "computer-name", "", "this computer's name (defaults to Windows' name)")
	flags.StringVar(&o.out, "out", "", "private folder for the results")
	flags.StringVar(&o.proxy, "proxy", "", "proxy address, when Windows' setting doesn't work")
	if flags.Parse(args) != nil || flags.NArg() != 0 || o.out == "" || !filepath.IsAbs(o.out) {
		return o, errors.New("use: oblivion-setup redeem --out <folder> (--installer <path> | --server <host> --code <XXXX-XXXX>) [--computer-name NAME] [--proxy host:port]")
	}
	return o, nil
}

// redeemResponse is HubSetupRedeemController's answer.
type redeemResponse struct {
	State           string            `json:"state"`
	Message         string            `json:"message"`
	Role            string            `json:"role"`
	InstallMode     *string           `json:"install_mode"`
	Profile         json.RawMessage   `json:"profile"`
	ComputerProfile json.RawMessage   `json:"computer_profile"`
	Display         map[string]string `json:"display"`
}

// redeemResult is what goes into setup.ini.
type redeemResult struct {
	state, message, server, code string
	section                      map[string]string
	display                      map[string]string
}

func runRedeem(ctx context.Context, args []string, output io.Writer) error {
	o, err := parseRedeemOptions(args)
	if err != nil {
		return err
	}
	if err = makePrivateFolder(o.out); err != nil {
		return errors.New("cannot create the private setup folder")
	}
	result, code := redeem(ctx, o)
	if err = writeRedeemResult(o.out, result); err != nil {
		return errors.New("cannot save the setup details")
	}
	fmt.Fprintf(output, "redeem: %s\n", result.state)
	if code != 0 {
		return exitError{code: code, message: "redeem: " + result.state + ": " + result.message}
	}
	return nil
}

func redeem(ctx context.Context, o redeemOptions) (redeemResult, int) {
	server, code := normaliseHost(o.server), normaliseCode(o.code)
	if o.installer != "" {
		fromName, codeFromName := parseInstallerName(o.installer)
		if server == "" {
			server = fromName
		}
		if code == "" {
			code = codeFromName
		}
		if server == "" {
			server = zoneServer(o.installer)
		}
	}
	if server == "" {
		server = normaliseHost(o.defaultServer)
	}
	r := redeemResult{server: server, code: code, section: map[string]string{}, display: map[string]string{}}
	if code == "" || server == "" {
		r.state = "no_code"
		r.message = "This file's name was changed, so the code is missing. Type the 8-character code from Oblivion (Add a hub, step 2), or download the file again."
		if server == "" {
			r.section["server_known"] = "0"
		} else {
			r.section["server_known"] = "1"
		}
		return r, exitRedeemNoCode
	}
	computer := strings.ToUpper(strings.TrimSpace(o.computer))
	if computer == "" {
		if name, err := os.Hostname(); err == nil {
			computer = strings.ToUpper(name)
		}
	}
	if !computerNamePattern.MatchString(computer) {
		computer = ""
	}
	proxy, err := proxyFor(o.proxy)
	if err != nil {
		r.state, r.message = "proxy", "That proxy address isn't right. Type it like proxy.house.local:8080."
		return r, exitRedeemProxy
	}
	response, leaf, status, err := postRedeem(ctx, server, code, computer, proxy)
	if err != nil {
		return failure(r, err)
	}
	switch {
	case status == http.StatusOK && response.State == "ok":
	case status == http.StatusGone || status == http.StatusUnprocessableEntity:
		r.state = response.State
		r.message = plainMessage(response.Message)
		codes := map[string]int{"used": exitRedeemUsed, "expired": exitRedeemExpired, "cancelled": exitRedeemCancelled, "unknown": exitRedeemUnknown}
		if exit, ok := codes[r.state]; ok && r.message != "" {
			return r, exit
		}
		r.state, r.message = "unknown", "That setup code isn't right. Check the 8 characters and try again."
		return r, exitRedeemUnknown
	case status == http.StatusProxyAuthRequired:
		r.state, r.message = "proxy", "The proxy at this house asked for a password. Ask IT to allow "+server+" through it."
		return r, exitRedeemProxy
	case status == http.StatusTooManyRequests:
		r.state, r.message = "server_error", "Too many tries in a row. Wait a minute, then try again."
		return r, exitRedeemServer
	default:
		r.state, r.message = "server_error", "The Oblivion server had a problem and didn't set this computer up. Try again in a few minutes."
		return r, exitRedeemServer
	}
	return acceptRedeem(r, response, leaf, computer, o.out)
}

// acceptRedeem checks the answer belongs to the server we asked and writes the profiles.
func acceptRedeem(r redeemResult, response redeemResponse, leaf *x509.Certificate, computer, out string) (redeemResult, int) {
	untrusted := func() (redeemResult, int) {
		r.state = "untrusted"
		r.message = "The answer from " + r.server + " didn't match this installer, so nothing was set up. Download the file again from Oblivion."
		return r, exitRedeemUntrusted
	}
	now := time.Now()
	r.section["role"] = response.Role
	r.section["install_mode"] = ""
	if response.InstallMode != nil {
		r.section["install_mode"] = *response.InstallMode
	}
	r.section["computer_name"] = computer
	r.section["has_computer_profile"] = "0"
	r.section["has_site_profile"] = "0"
	switch response.Role {
	case "hub":
		site, err := collectorhost.ReadBootstrap(bytes.NewReader(response.Profile), now)
		if err != nil || !sameHost(site.DeploymentURL, r.server) {
			return untrusted()
		}
		// The profile pins the hub transport's key. When that's this server, the key we just
		// talked to must be the pinned one.
		if sameHost(site.ServerURL, r.server) && !pinMatches(leaf, site.TLSPin) {
			return untrusted()
		}
		if err = writePrivateFile(filepath.Join(out, "site-profile.json"), response.Profile); err != nil {
			return failure(r, err)
		}
		r.section["has_site_profile"] = "1"
		r.section["server_url"] = site.DeploymentURL
		r.section["collector_url"] = site.ServerURL
		r.section["organisation"] = site.Organisation
		r.section["site_label"] = site.SiteLabel
		if len(bytes.TrimSpace(response.ComputerProfile)) > 0 && !bytes.Equal(bytes.TrimSpace(response.ComputerProfile), []byte("null")) {
			p, err := agent.ReadBootstrap(bytes.NewReader(response.ComputerProfile), now)
			if err != nil || p.ServerURL != site.DeploymentURL {
				return untrusted()
			}
			if err = writePrivateFile(filepath.Join(out, "computer-profile.json"), response.ComputerProfile); err != nil {
				return failure(r, err)
			}
			r.section["has_computer_profile"] = "1"
		}
	case "computer":
		if response.InstallMode == nil || (*response.InstallMode != "staff" && *response.InstallMode != "full") {
			return untrusted()
		}
		p, err := agent.ReadBootstrap(bytes.NewReader(response.Profile), now)
		if err != nil || !sameHost(p.ServerURL, r.server) {
			return untrusted()
		}
		if err = writePrivateFile(filepath.Join(out, "computer-profile.json"), response.Profile); err != nil {
			return failure(r, err)
		}
		r.section["has_computer_profile"] = "1"
		r.section["server_url"] = p.ServerURL
		r.section["organisation"] = p.Organisation
	default:
		return untrusted()
	}
	for _, name := range []string{"brand_name", "site_name", "site_address", "made_by"} {
		r.display[name] = response.Display[name]
	}
	r.display["made_at"] = localTime(response.Display["made_at"])
	r.display["expires_at"] = localTime(response.Display["expires_at"])
	r.display["person"] = personName()
	r.section["can_elevate"] = boolText(canElevate())
	r.section["elevated"] = boolText(isElevated())
	r.state = "ok"
	return r, 0
}

func postRedeem(ctx context.Context, server, code, computer string, proxy func(*http.Request) (*url.URL, error)) (redeemResponse, *x509.Certificate, int, error) {
	var none redeemResponse
	body, err := json.Marshal(map[string]string{"code": code, "computer_name": computer})
	if err != nil {
		return none, nil, 0, err
	}
	// Windows checks the certificate (Go uses the system verifier when no roots are given).
	// No setting here can turn that off.
	transport := &http.Transport{
		Proxy:                 proxy,
		TLSClientConfig:       &tls.Config{MinVersion: tls.VersionTLS12},
		TLSHandshakeTimeout:   15 * time.Second,
		ResponseHeaderTimeout: 20 * time.Second,
		DialContext:           (&net.Dialer{Timeout: 15 * time.Second}).DialContext,
		ForceAttemptHTTP2:     true,
	}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport, Timeout: 45 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, "https://"+server+"/api/monitoring/collectors/setup/redeem", bytes.NewReader(body))
	if err != nil {
		return none, nil, 0, err
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	request.Header.Set("User-Agent", "Oblivion-Setup/"+version)
	response, err := client.Do(request)
	if err != nil {
		return none, nil, 0, err
	}
	defer response.Body.Close()
	var leaf *x509.Certificate
	if response.TLS != nil && len(response.TLS.PeerCertificates) > 0 {
		leaf = response.TLS.PeerCertificates[0]
	}
	data, err := io.ReadAll(io.LimitReader(response.Body, 128*1024+1))
	if err != nil || len(data) > 128*1024 {
		return none, leaf, response.StatusCode, errServerAnswer
	}
	var parsed redeemResponse
	if response.StatusCode == http.StatusOK || response.StatusCode == http.StatusGone || response.StatusCode == http.StatusUnprocessableEntity {
		if json.Unmarshal(data, &parsed) != nil {
			return none, leaf, response.StatusCode, errServerAnswer
		}
	}
	return parsed, leaf, response.StatusCode, nil
}

var errServerAnswer = errors.New("the server's answer could not be read")

// failure turns a connection problem into the plain message for the person and a detail
// line for "Save details for IT".
func failure(r redeemResult, err error) (redeemResult, int) {
	r.section["detail"] = oneLine(err.Error())
	var unknownAuthority x509.UnknownAuthorityError
	var hostname x509.HostnameError
	var invalid x509.CertificateInvalidError
	var tlsVerify *tls.CertificateVerificationError
	switch {
	case errors.Is(err, errServerAnswer):
		r.state, r.message = "server_error", "The Oblivion server had a problem and didn't set this computer up. Try again in a few minutes."
		return r, exitRedeemServer
	case errors.As(err, &unknownAuthority) || errors.As(err, &hostname) || errors.As(err, &invalid) || errors.As(err, &tlsVerify):
		r.state = "untrusted"
		r.message = "Windows doesn't trust the certificate " + r.server + " showed, so nothing was set up. A firewall that inspects secure traffic often causes this. Ask IT to let " + r.server + " through unchanged."
		return r, exitRedeemUntrusted
	case strings.Contains(err.Error(), "proxyconnect") || strings.Contains(strings.ToLower(err.Error()), "proxy"):
		r.state = "proxy"
		r.message = "The proxy at this house didn't let this computer reach " + r.server + ". Ask IT to allow " + r.server + " on port 443, or try another proxy."
		return r, exitRedeemProxy
	default:
		r.state = "unreachable"
		r.message = "This computer has internet, but the Oblivion server didn't answer. A firewall at the house often blocks it. Ask IT to allow " + hostOnly(r.server) + " on port 443."
		return r, exitRedeemUnreachable
	}
}

func sameHost(origin, server string) bool {
	u, err := url.Parse(origin)
	return err == nil && u.Scheme == "https" && strings.EqualFold(u.Host, server)
}

func hostOnly(server string) string {
	if host, _, err := net.SplitHostPort(server); err == nil {
		return host
	}
	return server
}

// pinMatches compares the server's key with a "sha256//<base64>" pin, as curl does.
func pinMatches(leaf *x509.Certificate, pin string) bool {
	if leaf == nil {
		return false
	}
	sum := sha256.Sum256(leaf.RawSubjectPublicKeyInfo)
	return "sha256//"+base64.StdEncoding.EncodeToString(sum[:]) == pin
}

// plainMessage keeps the server's sentence for the person, without control characters.
func plainMessage(message string) string {
	message = oneLine(message)
	if len([]rune(message)) > 300 {
		return ""
	}
	return message
}

func oneLine(text string) string {
	return strings.Join(strings.FieldsFunc(text, func(r rune) bool { return r < 0x20 || r == 0x7f }), " ")
}

// localTime shows the server's time in this computer's time zone, as the screens do:
// "Thu 29 Oct, 10:14 am".
func localTime(value string) string {
	t, err := time.Parse(time.RFC3339, value)
	if err != nil {
		return ""
	}
	return t.Local().Format("Mon 2 Jan, 3:04 pm")
}

func boolText(value bool) string {
	if value {
		return "1"
	}
	return "0"
}

func writeRedeemResult(out string, r redeemResult) error {
	result := map[string]string{"state": r.state, "message": r.message, "server": r.server, "code": r.code}
	for name, value := range r.section {
		result[name] = value
	}
	return writeINI(filepath.Join(out, "setup.ini"), []iniSection{{"result", result}, {"display", r.display}})
}
