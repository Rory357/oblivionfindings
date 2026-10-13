package eventsource

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"testing"
)

const testMessage = "C:\\Windows\\System32\\EventCreate.exe"

type memoryKey struct {
	state        snapshot
	puts         int
	failName     string
	flushFailure bool
	deleted      bool
	afterPut     func(string)
}

func (k *memoryKey) Read() (snapshot, error) {
	copy := k.state
	copy.values = map[string]value{}
	for name, v := range k.state.values {
		copy.values[name] = v
	}
	return copy, nil
}
func (k *memoryKey) Put(name string, v value) error {
	if name == k.failName {
		return errors.New("write failed")
	}
	k.state.values[name] = v
	k.puts++
	if k.afterPut != nil {
		k.afterPut(name)
	}
	return nil
}
func (k *memoryKey) Flush() error {
	if k.flushFailure {
		return errors.New("flush failed")
	}
	return nil
}
func (k *memoryKey) Delete() error { k.deleted = true; return nil }
func (k *memoryKey) Close() error  { return nil }

type memoryStore struct {
	keys    map[string]*memoryKey
	opens   int
	newFail string
}

func (s *memoryStore) Open(name string, create bool) (sourceKey, bool, error) {
	s.opens++
	if k := s.keys[name]; k != nil && !k.deleted {
		return k, false, nil
	}
	if !create {
		return nil, false, ErrAbsent
	}
	k := &memoryKey{state: snapshot{secure: true, values: map[string]value{}}, failName: s.newFail}
	s.keys[name] = k
	return k, true, nil
}
func testSource(t *testing.T, name string) Source {
	t.Helper()
	s, err := New(name, strings.Repeat("a", 32), strings.Repeat("b", 64))
	if err != nil || !s.valid() {
		t.Fatal(err)
	}
	return s
}
func TestEnsureOwnsOnlyFixedSourcesAndIsIdempotent(t *testing.T) {
	store := &memoryStore{keys: map[string]*memoryKey{}}
	for _, name := range []string{"OblivionAgent", "OblivionCollector"} {
		s := testSource(t, name)
		if err := ensure(context.Background(), store, s, testMessage); err != nil {
			t.Fatal(err)
		}
		key := store.keys[name]
		if key.puts != 4 || !reflect.DeepEqual(key.state.values, expectedValues(s, testMessage)) {
			t.Fatal("incomplete source")
		}
		if err := ensure(context.Background(), store, s, testMessage); err != nil || key.puts != 4 {
			t.Fatal("retry rewrote registered values", err)
		}
		if err := inspect(store, s, testMessage, false, false); err != nil {
			t.Fatal(err)
		}
	}
	if store.keys["OblivionAgent"].state.values[ownerValue] == store.keys["OblivionCollector"].state.values[ownerValue] {
		t.Fatal("sources share ownership")
	}
	for _, name := range []string{"Application", "Other", "OblivionAgent\\Child", "oblivionagent"} {
		if _, err := New(name, strings.Repeat("a", 32), strings.Repeat("b", 64)); err == nil {
			t.Fatal("arbitrary source accepted")
		}
	}
}
func TestPartialOwnedRegistrationRecoversWithoutAdoptingChangedValues(t *testing.T) {
	for _, failed := range []string{"CustomSource", "EventMessageFile", "TypesSupported"} {
		store := &memoryStore{keys: map[string]*memoryKey{}, newFail: failed}
		s := testSource(t, "OblivionAgent")
		if ensure(context.Background(), store, s, testMessage) == nil {
			t.Fatal("write failure ignored")
		}
		if inspect(store, s, testMessage, false, false) == nil {
			t.Fatal("partial source called complete")
		}
		if err := inspect(store, s, testMessage, true, true); err != nil {
			t.Fatal("owned partial cannot be reviewed", err)
		}
		store.keys[s.Name].failName = ""
		if err := ensure(context.Background(), store, s, testMessage); err != nil {
			t.Fatal("partial retry failed", err)
		}
		if err := remove(store, s, testMessage); err != nil || !store.keys[s.Name].deleted {
			t.Fatal("owned cleanup failed", err)
		}
	}
}
func TestForeignMalformedAndInsecureSourcesArePreserved(t *testing.T) {
	s := testSource(t, "OblivionAgent")
	changes := []func(*snapshot){
		func(v *snapshot) { v.secure = false }, func(v *snapshot) { v.children = true }, func(v *snapshot) { delete(v.values, ownerValue) },
		func(v *snapshot) { v.values[ownerValue] = value{kind: 1, text: "foreign"} },
		func(v *snapshot) { v.values["EventMessageFile"] = value{kind: 1, text: "C:\\foreign.dll"} },
		func(v *snapshot) { v.values["EventMessageFile"] = value{kind: 2, text: testMessage} },
		func(v *snapshot) { v.values["CustomSource"] = value{kind: 4, number: 2} },
		func(v *snapshot) { v.values["SymbolicLinkValue"] = value{kind: 6, text: "elsewhere"} },
		func(v *snapshot) { v.values["Unexpected"] = value{kind: 1, text: "preserve"} },
	}
	for _, change := range changes {
		k := &memoryKey{state: snapshot{secure: true, values: expectedValues(s, testMessage)}}
		change(&k.state)
		store := &memoryStore{keys: map[string]*memoryKey{s.Name: k}}
		before, _ := k.Read()
		if ensure(context.Background(), store, s, testMessage) == nil || remove(store, s, testMessage) == nil || inspect(store, s, testMessage, true, true) == nil {
			t.Fatal("foreign source accepted")
		}
		if k.puts != 0 || k.deleted || !reflect.DeepEqual(k.state, before) {
			t.Fatal("foreign source changed")
		}
	}
}
func TestOwnershipWriteFailureAndCancellationStayRecoverableOrRequireReview(t *testing.T) {
	s := testSource(t, "OblivionAgent")
	store := &memoryStore{keys: map[string]*memoryKey{}, newFail: ownerValue}
	if ensure(context.Background(), store, s, testMessage) == nil {
		t.Fatal("owner write failure ignored")
	}
	store.keys[s.Name].failName = ""
	if ensure(context.Background(), store, s, testMessage) == nil || remove(store, s, testMessage) == nil || store.keys[s.Name].deleted {
		t.Fatal("ownerless key adopted or deleted")
	}
	cancelled, cancel := context.WithCancel(context.Background())
	cancel()
	empty := &memoryStore{keys: map[string]*memoryKey{}}
	if ensure(cancelled, empty, s, testMessage) == nil || empty.opens != 0 {
		t.Fatal("cancelled setup touched registry")
	}
	ctx, stop := context.WithCancel(context.Background())
	defer stop()
	k := &memoryKey{state: snapshot{secure: true, values: map[string]value{ownerValue: {kind: 1, text: s.Owner}}}, afterPut: func(string) { stop() }}
	pending := &memoryStore{keys: map[string]*memoryKey{s.Name: k}}
	if !errors.Is(ensure(ctx, pending, s, testMessage), context.Canceled) {
		t.Fatal("mid-write cancellation ignored")
	}
	if err := remove(pending, s, testMessage); err != nil || !k.deleted {
		t.Fatal("cancelled owned source not cleaned")
	}
}
func TestFlushFailureNeverReportsSuccess(t *testing.T) {
	s := testSource(t, "OblivionAgent")
	k := &memoryKey{state: snapshot{secure: true, values: expectedValues(s, testMessage)}, flushFailure: true}
	store := &memoryStore{keys: map[string]*memoryKey{s.Name: k}}
	if ensure(context.Background(), store, s, testMessage) == nil {
		t.Fatal("flush failure reported success")
	}
}
func TestOwnershipBindingRejectsOtherAttemptReleaseOrRole(t *testing.T) {
	s := testSource(t, "OblivionAgent")
	store := &memoryStore{keys: map[string]*memoryKey{}}
	if err := ensure(context.Background(), store, s, testMessage); err != nil {
		t.Fatal(err)
	}
	for _, candidate := range []Source{{s.Name, strings.Replace(s.Owner, strings.Repeat("a", 32), strings.Repeat("c", 32), 1)}, {s.Name, strings.Replace(s.Owner, strings.Repeat("b", 64), strings.Repeat("d", 64), 1)}, {"OblivionCollector", s.Owner}, {s.Name, ""}} {
		if remove(store, candidate, testMessage) == nil {
			t.Fatal("different ownership removed source")
		}
	}
	if store.keys[s.Name].deleted {
		t.Fatal("original ownership lost")
	}
}
