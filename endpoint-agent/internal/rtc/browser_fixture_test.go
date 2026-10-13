package rtc

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/jpeg"
	"net"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

// Opt-in interoperability fixture: loopback relay, generated pixels, in-memory input only.
// It never imports or calls a desktop capture/input implementation.
func TestBrowserViewerInteroperabilityFixture(t *testing.T) {
	destination := os.Getenv("OBLI_BROWSER_FIXTURE")
	if destination == "" {
		t.Skip("explicit browser fixture only")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Minute)
	defer cancel()
	sid, desktop, offerID := "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb", "cccccccc-cccc-4ccc-8ccc-cccccccccccc"
	var alive, active atomic.Bool
	alive.Store(true)
	var mu sync.Mutex
	inputs := []Input{}
	relay := relayFixture(t)
	// The fixture's browser has enough time for UI interaction; this is still a short-lived credential.
	expires := time.Now().Add(2 * time.Minute).UTC().Truncate(time.Second)
	relay[0].Username = strconv.FormatInt(expires.Unix(), 10) + ":obli-agent-" + strings.Repeat("a", 64)
	relay[0].Credential = fixtureRelayPassword(relay[0].Username)
	peer, err := newPeer(ctx, loopbackAPI(), Config{SessionUUID: sid, DesktopSession: desktop, OfferUUID: offerID, AccessMode: "control", Servers: relay,
		Authorize: func(op string) bool { return alive.Load() && (op == "negotiate" || active.Load()) },
		Input: func(value Input) error {
			mu.Lock()
			defer mu.Unlock()
			if len(inputs) >= 1024 {
				return ErrUnavailable
			}
			inputs = append(inputs, value)
			return nil
		},
	}, true)
	if err != nil {
		t.Fatal(err)
	}
	defer peer.Close()
	offer, err := peer.Offer(ctx)
	if err != nil {
		t.Fatal(err)
	}
	picture := image.NewRGBA(image.Rect(0, 0, 640, 360))
	draw.Draw(picture, picture.Bounds(), &image.Uniform{color.RGBA{35, 38, 49, 255}}, image.Point{}, draw.Src)
	draw.Draw(picture, image.Rect(24, 24, 616, 336), &image.Uniform{color.RGBA{246, 247, 250, 255}}, image.Point{}, draw.Src)
	draw.Draw(picture, image.Rect(24, 24, 616, 70), &image.Uniform{color.RGBA{112, 93, 146, 255}}, image.Point{}, draw.Src)
	for row := 0; row < 4; row++ {
		draw.Draw(picture, image.Rect(48, 100+row*48, 430, 115+row*48), &image.Uniform{color.RGBA{185, 187, 199, 255}}, image.Point{}, draw.Src)
	}
	var encoded bytes.Buffer
	if err = jpeg.Encode(&encoded, picture, &jpeg.Options{Quality: 80}); err != nil {
		t.Fatal(err)
	}
	go func() {
		select {
		case <-ctx.Done():
			return
		case <-peer.Ready():
		}
		active.Store(true)
		timer := time.NewTicker(200 * time.Millisecond)
		defer timer.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-peer.Done():
				return
			case <-timer.C:
				if peer.SendFrame(encoded.Bytes(), 640, 360) != nil && !alive.Load() {
					return
				}
			}
		}
	}()
	var allowed atomic.Bool
	done := make(chan struct{})
	var doneOnce sync.Once
	mux := http.NewServeMux()
	reply := func(w http.ResponseWriter, data any) {
		w.Header().Set("Content-Type", "application/json")
		w.Header().Set("Cache-Control", "no-store")
		_ = json.NewEncoder(w).Encode(data)
	}
	session := func() map[string]any {
		status := "requested"
		if allowed.Load() {
			status = "allowed"
		}
		if active.Load() {
			status = "active"
		}
		if !alive.Load() {
			status = "stopped"
		}
		return map[string]any{"uuid": sid, "device_id": 9100, "status": status, "access_mode": "control", "consent_mode": "attended", "recording": false,
			"request_expires_at": time.Now().Add(time.Minute).UTC().Format(time.RFC3339), "session_expires_at": expires.Format(time.RFC3339)}
	}
	mux.HandleFunc("/fixture/allow", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			w.WriteHeader(405)
			return
		}
		allowed.Store(true)
		reply(w, map[string]bool{"allowed": true})
	})
	mux.HandleFunc("/fixture/stats", func(w http.ResponseWriter, r *http.Request) {
		mu.Lock()
		defer mu.Unlock()
		reply(w, map[string]any{"inputs": inputs, "stopped": !alive.Load()})
	})
	mux.HandleFunc("/fixture/finish", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			w.WriteHeader(405)
			return
		}
		reply(w, map[string]bool{"finished": true})
		doneOnce.Do(func() { close(done) })
	})
	mux.HandleFunc("/it/remote-support/devices/9100/connection-options", func(w http.ResponseWriter, r *http.Request) {
		reply(w, map[string]any{"actor_id": 9001, "device_id": 9100, "policy_version": 1, "enabled": true, "consent_mode": "attended", "allow_control": true, "max_minutes": 5})
	})
	mux.HandleFunc("/it/remote-support/devices/9100/sessions", func(w http.ResponseWriter, r *http.Request) {
		var data map[string]any
		if r.Method != "POST" || json.NewDecoder(http.MaxBytesReader(w, r.Body, 4096)).Decode(&data) != nil || data["access_mode"] != "control" {
			w.WriteHeader(422)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(201)
		reply(w, session())
	})
	base := "/it/remote-support/sessions/" + sid
	mux.HandleFunc(base, func(w http.ResponseWriter, r *http.Request) { reply(w, session()) })
	mux.HandleFunc(base+"/media", func(w http.ResponseWriter, r *http.Request) {
		if !allowed.Load() {
			w.WriteHeader(409)
			return
		}
		reply(w, map[string]any{"status": "allowed", "signal": map[string]any{"offer_uuid": offerID, "desktop_session": desktop, "access_mode": "control", "sdp": offer}})
	})
	mux.HandleFunc(base+"/relay", func(w http.ResponseWriter, r *http.Request) {
		if !allowed.Load() {
			w.WriteHeader(409)
			return
		}
		username := strconv.FormatInt(expires.Unix(), 10) + ":obli-technician-" + strings.Repeat("b", 64)
		reply(w, map[string]any{"type": "remote_relay", "version": 1, "role": "technician", "agent_uuid": offerID, "session_uuid": sid, "desktop_session": desktop, "access_mode": "control",
			"urls": relay[0].URLs, "username": username, "credential": fixtureRelayPassword(username), "credential_expires_at": expires.Format(time.RFC3339),
			"issued_at": time.Now().UTC().Format(time.RFC3339), "expires_at": time.Now().Add(15 * time.Second).UTC().Format(time.RFC3339)})
	})
	mux.HandleFunc(base+"/media/answer", func(w http.ResponseWriter, r *http.Request) {
		var data struct {
			Offer  string `json:"offer_uuid"`
			Viewer string `json:"viewer_uuid"`
			SDP    string `json:"sdp"`
		}
		if !allowed.Load() || r.Method != "POST" || json.NewDecoder(http.MaxBytesReader(w, r.Body, 65536)).Decode(&data) != nil || data.Offer != offerID || !validID(data.Viewer) || peer.ApplyAnswer(data.SDP) != nil {
			w.WriteHeader(409)
			return
		}
		reply(w, map[string]any{"status": "allowed", "offer_uuid": offerID, "viewer_uuid": data.Viewer})
	})
	mux.HandleFunc(base+"/stop", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			w.WriteHeader(405)
			return
		}
		alive.Store(false)
		peer.Close()
		reply(w, map[string]any{"uuid": sid, "status": "stopped"})
	})
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &http.Server{Handler: mux, ReadHeaderTimeout: 2 * time.Second, ReadTimeout: 5 * time.Second, WriteTimeout: 10 * time.Second}
	defer server.Close()
	info, _ := json.Marshal(map[string]string{"url": "http://" + listener.Addr().String()})
	if err = os.WriteFile(destination, info, 0600); err != nil {
		t.Fatal(err)
	}
	fmt.Println("Browser fixture ready", listener.Addr())
	go func() { _ = server.Serve(listener) }()
	select {
	case <-ctx.Done():
		t.Fatal("browser fixture did not finish")
	case <-done:
	}
	mu.Lock()
	defer mu.Unlock()
	if len(inputs) == 0 || alive.Load() {
		t.Fatal("browser did not demonstrate control and Stop")
	}
}
