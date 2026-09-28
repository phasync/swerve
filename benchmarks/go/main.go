// Hello world on Go's standard net/http. The listener sets SO_REUSEPORT, so that several processes
// can share the port, for example one per NUMA node: see ../README.md.
package main

import (
	"context"
	"net"
	"net/http"
	"os"
	"syscall"

	"golang.org/x/sys/unix"
)

func main() {
	lc := net.ListenConfig{Control: func(network, address string, c syscall.RawConn) error {
		var err error
		c.Control(func(fd uintptr) { err = unix.SetsockoptInt(int(fd), unix.SOL_SOCKET, unix.SO_REUSEPORT, 1) })
		return err
	}}
	ln, err := lc.Listen(context.Background(), "tcp", os.Args[1])
	if err != nil {
		panic(err)
	}
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		w.Write([]byte("Hello"))
	})
	panic(http.Serve(ln, nil))
}
