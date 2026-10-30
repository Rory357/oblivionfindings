package agent

import (
	"bufio"
	"context"
	"errors"
	"golang.org/x/sys/unix"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

func probe(ctx context.Context, inventory *Inventory) (string, error) {
	rawBoot, err := os.ReadFile("/proc/sys/kernel/random/boot_id")
	if err != nil || !uuidPattern.MatchString(strings.TrimSpace(string(rawBoot))) {
		return "", errors.New("Linux boot identity unavailable")
	}
	inventory.OSVersion = "Linux"
	if data, err := os.ReadFile("/etc/os-release"); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			if strings.HasPrefix(line, "PRETTY_NAME=") {
				inventory.OSVersion = safeText(strings.Trim(strings.TrimPrefix(line, "PRETTY_NAME="), "\""))
				break
			}
		}
	} else {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "os")
	}
	if data, err := os.ReadFile("/proc/meminfo"); err == nil {
		for _, line := range strings.Split(string(data), "\n") {
			fields := strings.Fields(line)
			if len(fields) < 2 {
				continue
			}
			n, e := strconv.ParseUint(fields[1], 10, 64)
			if e != nil {
				continue
			}
			n *= 1024
			if fields[0] == "MemTotal:" {
				inventory.MemoryTotal = &n
			}
			if fields[0] == "MemAvailable:" {
				inventory.MemoryAvailable = &n
			}
		}
	}
	if inventory.MemoryTotal == nil || inventory.MemoryAvailable == nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "memory")
	}
	if data, err := os.ReadFile("/proc/uptime"); err == nil {
		fields := strings.Fields(string(data))
		if len(fields) > 0 {
			if f, e := strconv.ParseFloat(fields[0], 64); e == nil && f >= 0 {
				n := uint64(f)
				inventory.Uptime = &n
			}
		}
	}
	if inventory.Uptime == nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "uptime")
	}
	total1, idle1, e1 := cpuCounters()
	timer := time.NewTimer(250 * time.Millisecond)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return "", ctx.Err()
	case <-timer.C:
	}
	total2, idle2, e2 := cpuCounters()
	if e1 == nil && e2 == nil && total2 > total1 && idle2 >= idle1 && idle2-idle1 <= total2-total1 {
		n := 100 * (1 - float64(idle2-idle1)/float64(total2-total1))
		inventory.CPU = &n
	} else {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "cpu")
	}
	probeLinuxDisks(inventory)
	probeLinuxSoftware(ctx, inventory)
	probeLinuxServices(ctx, inventory)
	return strings.TrimSpace(string(rawBoot)), nil
}
func cpuCounters() (uint64, uint64, error) {
	data, err := os.ReadFile("/proc/stat")
	if err != nil {
		return 0, 0, err
	}
	fields := strings.Fields(strings.SplitN(string(data), "\n", 2)[0])
	if len(fields) < 5 {
		return 0, 0, errors.New("invalid CPU counters")
	}
	var total, idle uint64
	// guest and guest_nice are already included in user/nice.
	for i := 1; i < len(fields) && i <= 8; i++ {
		n, e := strconv.ParseUint(fields[i], 10, 64)
		if e != nil {
			return 0, 0, e
		}
		total += n
		if i == 4 || i == 5 {
			idle += n
		}
	}
	return total, idle, nil
}
func probeLinuxDisks(inventory *Inventory) {
	f, err := os.Open("/proc/self/mounts")
	if err != nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "disks")
		return
	}
	defer f.Close()
	scan := bufio.NewScanner(f)
	seen := map[string]bool{}
	failed := false
	for scan.Scan() {
		fields := strings.Fields(scan.Text())
		if len(fields) < 3 {
			continue
		}
		switch fields[2] {
		case "ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "overlay":
		default:
			continue
		}
		path := strings.NewReplacer("\\040", " ", "\\011", "\t", "\\134", "\\").Replace(fields[1])
		if seen[fields[0]] {
			continue
		}
		seen[fields[0]] = true
		if len(inventory.Disks) >= 64 {
			failed = true
			break
		}
		var stat unix.Statfs_t
		if unix.Statfs(path, &stat) != nil {
			failed = true
			continue
		}
		inventory.Disks = append(inventory.Disks, Disk{Name: safeText(path), Total: stat.Blocks * uint64(stat.Bsize), Free: stat.Bavail * uint64(stat.Bsize)})
	}
	if failed || scan.Err() != nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "disks")
	}
}
func probeLinuxSoftware(ctx context.Context, inventory *Inventory) {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	var output []byte
	var err error
	if _, e := os.Stat("/usr/bin/dpkg-query"); e == nil {
		output, err = readCommand(ctx, "/usr/bin/dpkg-query", "-W", "-f=${db:Status-Abbrev}\t${binary:Package}\t${Version}\n")
	} else if _, e := os.Stat("/usr/bin/rpm"); e == nil {
		output, err = readCommand(ctx, "/usr/bin/rpm", "-qa", "--qf", "ii \t%{NAME}\t%{VERSION}-%{RELEASE}\n")
	} else {
		err = errors.New("unsupported package database")
	}
	if err != nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "software")
		return
	}
	inventory.SoftwareComplete = true
	for _, line := range strings.Split(string(output), "\n") {
		fields := strings.Split(line, "\t")
		if len(fields) != 3 || !strings.HasPrefix(fields[0], "ii") {
			continue
		}
		if len(inventory.Software) >= 5000 {
			inventory.SoftwareComplete = false
			break
		}
		inventory.Software = append(inventory.Software, Software{Name: safeText(fields[1]), Version: textPtr(fields[2])})
	}
}
func probeLinuxServices(ctx context.Context, inventory *Inventory) {
	ctx, cancel := context.WithTimeout(ctx, 15*time.Second)
	defer cancel()
	output, err := readCommand(ctx, "/usr/bin/systemctl", "list-units", "--type=service", "--all", "--no-legend", "--no-pager", "--plain")
	if err != nil {
		inventory.ProbeErrors = append(inventory.ProbeErrors, "services")
		return
	}
	inventory.ServicesComplete = true
	for _, line := range strings.Split(string(output), "\n") {
		fields := strings.Fields(line)
		if len(fields) < 4 {
			continue
		}
		if len(inventory.Services) >= 1000 {
			inventory.ServicesComplete = false
			break
		}
		state := "unknown"
		if fields[3] == "running" {
			state = "running"
		} else if fields[3] == "dead" || fields[3] == "failed" {
			state = "stopped"
		}
		inventory.Services = append(inventory.Services, Service{Name: safeText(filepath.Base(fields[0])), State: state})
	}
}
