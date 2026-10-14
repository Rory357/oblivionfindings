// Package eventsource owns only the two fixed Oblivion Windows logging sources.
package eventsource

import (
	"context"
	"errors"
	"regexp"
)

var ErrAbsent = errors.New("event source absent")
var ErrUnsafe = errors.New("Windows event source needs administrator review")
var hex32 = regexp.MustCompile("^[a-f0-9]{32}$")
var hex64 = regexp.MustCompile("^[a-f0-9]{64}$")

type Source struct{ Name, Owner string }

func New(name, attempt, manifest string) (Source, error) {
	if !validName(name) || !hex32.MatchString(attempt) || !hex64.MatchString(manifest) {
		return Source{}, ErrUnsafe
	}
	return Source{name, "Oblivion.Setup.v1:" + attempt + ":" + manifest + ":" + name}, nil
}
func validName(name string) bool { return name == "OblivionAgent" || name == "OblivionCollector" }
func (s Source) valid() bool {
	prefix := "Oblivion.Setup.v1:"
	if !validName(s.Name) || len(s.Owner) != len(prefix)+32+1+64+1+len(s.Name) {
		return false
	}
	return s.Owner[:len(prefix)] == prefix && hex32.MatchString(s.Owner[len(prefix):len(prefix)+32]) && s.Owner[len(prefix)+32] == ':' && hex64.MatchString(s.Owner[len(prefix)+33:len(prefix)+97]) && s.Owner[len(prefix)+97:] == ":"+s.Name
}

type value struct {
	kind   uint32
	text   string
	number uint32
}
type snapshot struct {
	secure   bool
	children bool
	values   map[string]value
}
type sourceKey interface {
	Read() (snapshot, error)
	Put(string, value) error
	Flush() error
	Delete() error
	Close() error
}
type sourceStore interface {
	Open(string, bool) (sourceKey, bool, error)
}

const ownerValue = "OblivionInstallation"

func expectedValues(s Source, message string) map[string]value {
	return map[string]value{
		ownerValue: {kind: 1, text: s.Owner}, "CustomSource": {kind: 4, number: 1}, "EventMessageFile": {kind: 1, text: message}, "TypesSupported": {kind: 4, number: 7},
	}
}
func validSnapshot(s Source, current snapshot, message string, partial bool) error {
	if !s.valid() || !current.secure || current.children || current.values[ownerValue] != (value{kind: 1, text: s.Owner}) {
		return ErrUnsafe
	}
	expected := expectedValues(s, message)
	if len(current.values) > len(expected) || (!partial && len(current.values) != len(expected)) {
		return ErrUnsafe
	}
	for name, actual := range current.values {
		want, ok := expected[name]
		if !ok || actual != want {
			return ErrUnsafe
		}
	}
	return nil
}
func inspect(store sourceStore, s Source, message string, partial bool, absentOK bool) error {
	if !s.valid() {
		return ErrUnsafe
	}
	key, _, err := store.Open(s.Name, false)
	if errors.Is(err, ErrAbsent) && absentOK {
		return nil
	}
	if err != nil {
		return err
	}
	defer key.Close()
	state, err := key.Read()
	if err != nil {
		return err
	}
	return validSnapshot(s, state, message, partial)
}
func ensure(ctx context.Context, store sourceStore, s Source, message string) error {
	if !s.valid() {
		return ErrUnsafe
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	key, created, err := store.Open(s.Name, true)
	if err != nil {
		return err
	}
	defer key.Close()
	state, err := key.Read()
	if err != nil {
		return err
	}
	if created {
		if !state.secure || state.children || len(state.values) != 0 {
			return ErrUnsafe
		}
		if err = key.Put(ownerValue, value{kind: 1, text: s.Owner}); err != nil {
			return err
		}
		if err = key.Flush(); err != nil {
			return err
		}
		state, err = key.Read()
		if err != nil {
			return err
		}
	}
	if err = validSnapshot(s, state, message, true); err != nil {
		return err
	}
	expected := expectedValues(s, message)
	for _, name := range []string{"CustomSource", "EventMessageFile", "TypesSupported"} {
		if err = ctx.Err(); err != nil {
			return err
		}
		if _, exists := state.values[name]; !exists {
			if err = key.Put(name, expected[name]); err != nil {
				return err
			}
		}
	}
	if err = key.Flush(); err != nil {
		return err
	}
	state, err = key.Read()
	if err != nil {
		return err
	}
	return validSnapshot(s, state, message, false)
}
func remove(store sourceStore, s Source, message string) error {
	if !s.valid() {
		return ErrUnsafe
	}
	key, _, err := store.Open(s.Name, false)
	if errors.Is(err, ErrAbsent) {
		return nil
	}
	if err != nil {
		return err
	}
	defer key.Close()
	state, err := key.Read()
	if err != nil {
		return err
	}
	if err = validSnapshot(s, state, message, true); err != nil {
		return err
	}
	return key.Delete()
}
