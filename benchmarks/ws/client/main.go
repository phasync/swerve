// wsbench: open many WebSocket connections to a fan-out server, publish timestamped messages
// through it, and measure how long each message takes to reach every connection.
//
//	go build -o wsbench . && ./wsbench -host 192.168.10.5 -ports 18400,18401,18402,18403 -conns 100000
//
// The server publishes a POST /publish body to every connection of /news (benchmarks/ws/swerve.php).
// Each message is "seq:unixnano" from this machine's clock, so latency is measured on one clock.
package main

import (
	"bufio"
	"crypto/rand"
	"encoding/base64"
	"encoding/binary"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type sample struct {
	mu        sync.Mutex
	latencies []time.Duration
}

var (
	host      = flag.String("host", "127.0.0.1", "server address")
	ports     = flag.String("ports", "18400", "comma-separated server ports; connections are spread over them")
	path      = flag.String("path", "/news", "WebSocket path")
	conns     = flag.Int("conns", 1000, "connections to open")
	rate      = flag.Int("rate", 5000, "connections opened per second")
	messages  = flag.Int("messages", 20, "messages to publish")
	interval  = flag.Duration("interval", time.Second, "time between messages")
	hold      = flag.Duration("hold", 2*time.Second, "wait after connecting, before publishing")
	settle    = flag.Duration("settle", 5*time.Second, "wait after the last message for deliveries")
	connected atomic.Int64
	failed    atomic.Int64
	closed    atomic.Int64
	samples   []*sample
)

func main() {
	flag.Parse()
	portList := strings.Split(*ports, ",")
	samples = make([]*sample, *messages)
	for i := range samples {
		samples[i] = &sample{latencies: make([]time.Duration, 0, *conns)}
	}

	start := time.Now()
	tick := time.NewTicker(time.Second / time.Duration(*rate))
	var wg sync.WaitGroup
	for i := 0; i < *conns; i++ {
		<-tick.C
		wg.Add(1)
		go func(port string) {
			defer wg.Done()
			open(net.JoinHostPort(*host, port))
		}(portList[i%len(portList)])
	}
	tick.Stop()
	// Wait for the handshakes to finish
	for connected.Load()+failed.Load() < int64(*conns) && time.Since(start) < time.Duration(*conns / *rate + 30)*time.Second {
		time.Sleep(100 * time.Millisecond)
	}
	fmt.Printf("connected %d, failed %d, in %.1f s\n", connected.Load(), failed.Load(), time.Since(start).Seconds())
	time.Sleep(*hold)

	publishURL := "http://" + net.JoinHostPort(*host, portList[0]) + "/publish"
	client := &http.Client{Timeout: 10 * time.Second}
	for seq := 0; seq < *messages; seq++ {
		body := fmt.Sprintf("%d:%d", seq, time.Now().UnixNano())
		resp, err := client.Post(publishURL, "text/plain", strings.NewReader(body))
		if err != nil {
			fmt.Fprintln(os.Stderr, "publish:", err)
		} else {
			io.Copy(io.Discard, resp.Body)
			resp.Body.Close()
		}
		time.Sleep(*interval)
	}
	time.Sleep(*settle)

	fmt.Printf("%-4s %9s %9s %9s %9s %9s\n", "msg", "received", "p50", "p99", "p99.9", "last")
	for seq, s := range samples {
		s.mu.Lock()
		l := s.latencies
		sort.Slice(l, func(a, b int) bool { return l[a] < l[b] })
		if len(l) == 0 {
			fmt.Printf("%-4d %9d\n", seq, 0)
		} else {
			fmt.Printf("%-4d %9d %9s %9s %9s %9s\n", seq, len(l), ms(l, 0.50), ms(l, 0.99), ms(l, 0.999), ms(l, 1))
		}
		s.mu.Unlock()
	}
	fmt.Printf("still connected at the end: %d (closed by the server: %d)\n", connected.Load()-closed.Load(), closed.Load())
}

func ms(l []time.Duration, q float64) string {
	i := int(q * float64(len(l)-1))
	return fmt.Sprintf("%.1fms", float64(l[i].Microseconds())/1000)
}

func open(addr string) {
	c, err := net.DialTimeout("tcp", addr, 10*time.Second)
	if err != nil {
		failed.Add(1)
		return
	}
	key := make([]byte, 16)
	rand.Read(key)
	fmt.Fprintf(c, "GET %s HTTP/1.1\r\nHost: %s\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\nSec-WebSocket-Version: 13\r\n\r\n",
		*path, addr, base64.StdEncoding.EncodeToString(key))
	r := bufio.NewReaderSize(c, 4096)
	status, err := r.ReadString('\n')
	if err != nil || !strings.Contains(status, " 101 ") {
		failed.Add(1)
		c.Close()
		return
	}
	for {
		line, err := r.ReadString('\n')
		if err != nil {
			failed.Add(1)
			c.Close()
			return
		}
		if line == "\r\n" {
			break
		}
	}
	connected.Add(1)
	defer c.Close()
	for {
		opcode, payload, err := readFrame(r)
		if err != nil {
			closed.Add(1)
			return
		}
		switch opcode {
		case 1, 2:
			now := time.Now().UnixNano()
			seqText, tsText, ok := strings.Cut(string(payload), ":")
			seq, err1 := strconv.Atoi(seqText)
			ts, err2 := strconv.ParseInt(tsText, 10, 64)
			if ok && err1 == nil && err2 == nil && seq >= 0 && seq < len(samples) {
				s := samples[seq]
				s.mu.Lock()
				s.latencies = append(s.latencies, time.Duration(now-ts))
				s.mu.Unlock()
			}
		case 9:
			writeFrame(c, 10, payload)
		case 8:
			writeFrame(c, 8, payload)
			closed.Add(1)
			return
		}
	}
}

func readFrame(r *bufio.Reader) (byte, []byte, error) {
	var head [2]byte
	if _, err := io.ReadFull(r, head[:]); err != nil {
		return 0, nil, err
	}
	length := uint64(head[1] & 0x7F)
	switch length {
	case 126:
		var ext [2]byte
		if _, err := io.ReadFull(r, ext[:]); err != nil {
			return 0, nil, err
		}
		length = uint64(binary.BigEndian.Uint16(ext[:]))
	case 127:
		var ext [8]byte
		if _, err := io.ReadFull(r, ext[:]); err != nil {
			return 0, nil, err
		}
		length = binary.BigEndian.Uint64(ext[:])
	}
	payload := make([]byte, length)
	if _, err := io.ReadFull(r, payload); err != nil {
		return 0, nil, err
	}
	return head[0] & 0x0F, payload, nil
}

// A client frame must be masked
func writeFrame(c net.Conn, opcode byte, payload []byte) {
	var mask [4]byte
	rand.Read(mask[:])
	frame := []byte{0x80 | opcode, 0x80 | byte(len(payload))}
	frame = append(frame, mask[:]...)
	for i, b := range payload {
		frame = append(frame, b^mask[i%4])
	}
	c.Write(frame)
}
