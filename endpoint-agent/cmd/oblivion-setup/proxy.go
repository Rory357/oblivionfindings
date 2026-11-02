package main

import (
	"errors"
	"net"
	"net/http"
	"net/url"
	"os"
	"strings"
)

// The installer goes out on port 443 only, through the Windows proxy when one is set
// (windows tab, "How it's built", Network). A proxy script (PAC) isn't read; the person can
// type the proxy with "Use a proxy…" instead.

// proxyFor picks the proxy: the address the person typed, else this user's Windows proxy
// setting, else the usual environment variables.
func proxyFor(manual string) (func(*http.Request) (*url.URL, error), error) {
	if strings.TrimSpace(manual) != "" {
		u, err := parseProxyAddress(manual)
		if err != nil {
			return nil, err
		}
		return http.ProxyURL(u), nil
	}
	if server, override, ok := windowsProxySetting(); ok {
		if u, bypass, ok := parseProxySetting(server, override); ok {
			return func(r *http.Request) (*url.URL, error) {
				if bypassed(r.URL.Hostname(), bypass) {
					return nil, nil
				}
				return u, nil
			}, nil
		}
	}
	return http.ProxyFromEnvironment, nil
}

// parseProxyAddress accepts "host:port" or "http://host:port", with no user name or path.
func parseProxyAddress(raw string) (*url.URL, error) {
	raw = strings.TrimSpace(raw)
	if !strings.Contains(raw, "://") {
		raw = "http://" + raw
	}
	u, err := url.Parse(raw)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() == "" || u.User != nil ||
		(u.Path != "" && u.Path != "/") || u.RawQuery != "" || u.Fragment != "" {
		return nil, errors.New("invalid proxy address")
	}
	if u.Port() == "" {
		u.Host = net.JoinHostPort(u.Hostname(), "80")
	}
	u.Path = ""
	return u, nil
}

// parseProxySetting reads Windows' ProxyServer ("host:port" or "http=h:p;https=h:p") and
// ProxyOverride ("<local>;*.house.local").
func parseProxySetting(server, override string) (*url.URL, []string, bool) {
	server = strings.TrimSpace(server)
	if strings.Contains(server, "=") {
		chosen := ""
		for _, part := range strings.Split(server, ";") {
			scheme, address, found := strings.Cut(strings.TrimSpace(part), "=")
			if !found {
				continue
			}
			if strings.EqualFold(scheme, "https") || (strings.EqualFold(scheme, "http") && chosen == "") {
				chosen = address
			}
		}
		server = chosen
	}
	if server == "" {
		return nil, nil, false
	}
	u, err := parseProxyAddress(server)
	if err != nil {
		return nil, nil, false
	}
	var bypass []string
	for _, item := range strings.Split(override, ";") {
		if item = strings.ToLower(strings.TrimSpace(item)); item != "" {
			bypass = append(bypass, item)
		}
	}
	return u, bypass, true
}

// bypassed applies Windows' bypass list: "<local>" means names without a dot, and "*" is a
// wildcard.
func bypassed(host string, list []string) bool {
	host = strings.ToLower(host)
	for _, item := range list {
		if item == "<local>" {
			if !strings.Contains(host, ".") {
				return true
			}
			continue
		}
		if wildcardMatch(item, host) {
			return true
		}
	}
	return false
}

func wildcardMatch(pattern, text string) bool {
	if !strings.Contains(pattern, "*") {
		return pattern == text
	}
	parts := strings.Split(pattern, "*")
	if !strings.HasPrefix(text, parts[0]) {
		return false
	}
	text = text[len(parts[0]):]
	for i, part := range parts[1:] {
		if i == len(parts)-2 {
			return strings.HasSuffix(text, part)
		}
		index := strings.Index(text, part)
		if index < 0 {
			return false
		}
		text = text[index+len(part):]
	}
	return true
}

// useProxyForEnrolment passes the same proxy to the computer agent's enrolment, which runs in
// this process and reads the usual environment variables. The hub's own PHP enrolment and the
// installed services don't use it.
func useProxyForEnrolment(manual string) {
	if os.Getenv("HTTPS_PROXY") != "" || os.Getenv("https_proxy") != "" {
		return
	}
	if strings.TrimSpace(manual) != "" {
		if u, err := parseProxyAddress(manual); err == nil {
			os.Setenv("HTTPS_PROXY", u.String())
		}
		return
	}
	if server, override, ok := windowsProxySetting(); ok {
		if u, bypass, ok := parseProxySetting(server, override); ok {
			os.Setenv("HTTPS_PROXY", u.String())
			var plain []string
			for _, item := range bypass {
				if item != "<local>" && !strings.Contains(item, "*") {
					plain = append(plain, item)
				} else if strings.HasPrefix(item, "*.") && !strings.Contains(item[2:], "*") {
					plain = append(plain, item[1:])
				}
			}
			if len(plain) > 0 {
				os.Setenv("NO_PROXY", strings.Join(plain, ","))
			}
		}
	}
}
