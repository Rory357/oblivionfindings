package main

import (
	"bytes"
	"encoding/binary"
	"sort"
	"strings"
	"unicode/utf16"
)

// The Inno Setup screens read results with GetIniString. Windows only reads non-English
// letters (Tōtara) from an INI file saved as UTF-16 with a byte-order mark.
type iniSection struct {
	name   string
	values map[string]string
}

func encodeINI(sections []iniSection) []byte {
	var text strings.Builder
	for _, section := range sections {
		text.WriteString("[" + section.name + "]\r\n")
		names := make([]string, 0, len(section.values))
		for name := range section.values {
			names = append(names, name)
		}
		sort.Strings(names)
		for _, name := range names {
			text.WriteString(name + "=" + iniValue(section.values[name]) + "\r\n")
		}
	}
	units := utf16.Encode([]rune(text.String()))
	var out bytes.Buffer
	out.Write([]byte{0xff, 0xfe})
	_ = binary.Write(&out, binary.LittleEndian, units)
	return out.Bytes()
}

// iniValue keeps a value on one line. Windows strips one pair of surrounding quotes, so a
// value that starts and ends with a quote is wrapped once more.
func iniValue(value string) string {
	value = oneLine(value)
	if len(value) >= 2 && strings.HasPrefix(value, `"`) && strings.HasSuffix(value, `"`) {
		return `"` + value + `"`
	}
	return value
}

func writeINI(path string, sections []iniSection) error {
	return writePrivateFile(path, encodeINI(sections))
}
