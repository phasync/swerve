// Go's net/http streaming a file from disk with http.ServeFile
package main

import (
	"net"
	"net/http"
	"os"
	"strings"
)

func main() {
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		http.ServeFile(w, r, os.Getenv("FILE"))
	})
	network, addr := "tcp", os.Args[1]
	if strings.HasPrefix(addr, "unix:") {
		network, addr = "unix", addr[5:]
	}
	l, err := net.Listen(network, addr)
	if err != nil {
		panic(err)
	}
	panic(http.Serve(l, nil))
}
