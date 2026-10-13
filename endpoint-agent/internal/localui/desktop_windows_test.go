package localui

import (
	"encoding/binary"
	"testing"
)

func TestDesktopStateRequiresExactActiveUnlockedWindowsSession(t *testing.T) {
	data := make([]byte, 20)
	binary.LittleEndian.PutUint32(data, 1)
	binary.LittleEndian.PutUint32(data[8:], 8)
	binary.LittleEndian.PutUint32(data[16:], 1)
	if !unlockedSessionPrefix(data, 8) {
		t.Fatal("valid Windows session prefix")
	}
	for _, offset := range []int{0, 8, 12, 16} {
		copy := append([]byte(nil), data...)
		binary.LittleEndian.PutUint32(copy[offset:], 99)
		if unlockedSessionPrefix(copy, 8) {
			t.Fatal("unknown Windows state accepted")
		}
	}
	if unlockedSessionPrefix(data[:19], 8) || unlockedSessionPrefix(data, 9) {
		t.Fatal("truncated or wrong desktop accepted")
	}
	binary.LittleEndian.PutUint32(data[16:], 0)
	if unlockedSessionPrefix(data, 8) {
		t.Fatal("locked desktop accepted")
	}
}
