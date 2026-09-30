// Go's net/http: one process, GOMAXPROCS threads
package main

import (
	"net/http"
	"os"
)

func main() {
	http.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		w.Write([]byte("Hello, World!"))
	})
	panic(http.ListenAndServe(os.Args[1], nil))
}
