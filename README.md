# Swerve

`swerve` is an application protocol pattern, and an implementation for serving long-running PHP applications.

A `swerve.php` file on the application root must return a PSR-15 RequestHandlerInterface object. This request handler object will be used to serve HTTP requests to the server.

`phasync/swerve` is a fast web server written primarily in PHP, and it can be used to run PHP applications straight from the command line without needing any external web server implementation.

## Running the Server

The server supports running PSR-15 compliant applications. To start the server, you need to create a PHP file that returns an instance of `Psr\Http\Server\RequestHandlerInterface`. By default, this file should be named `swerve.php` and located in the directory from where you launch the server.

### Example

To run the server, execute the following command:

```bash
./vendor/bin/swerve
```

## Command Line Arguments

You can customize the server's behavior using the following command-line arguments:

```bash
phasync-server [-a <address>] [-p <port>] [path/to/swerve.php]
```

- `-a <address>`: Sets the IP address to bind the server (default: `0.0.0.0`).
- `-p <port>`: Sets the port number for the server (default: `80`).
- `[path/to/sapi.php]`: Specifies the path to your PSR-15 compliant application file if it differs from the default `sapi.php`.

## Example Usage

### Default Execution

```bash
./vendor/bin/phasync-server
```

This will start the server on `0.0.0.0` at port `80` using the default `sapi.php` file in the current directory.

### Custom Address and Port

```bash
./vendor/bin/swerve -a 127.0.0.1 -p 8080
```

This command starts the server on `127.0.0.1` at port `8080`.

### Specifying a Custom `swerve.php` Path

```bash
./vendor/bin/swerve /path/to/swerve.php
```

This will start the server using the specified `swerve.php` file.
