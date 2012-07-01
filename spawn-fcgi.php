#!/usr/bin/php
<?php
/*
 * Spawn-FCGI.php: A script to translate PHP requests into FastCGI
 * requests, thus allowing hosting of FastCGI apps (good) on any
 * PHP-compatible web host (cheap, common).
 *
 * This is almost a direct port of Flup's FastCGI test harness,
 * flup_fcgi_client.py (c) 2006 Allan Saddi <allan@saddi.com> +
 * 2011 Vladimir Rusinov <vladimir@greenmice.info>
 *
 * Modified to take request data from the environment rather than
 * from function parameters.
 *
 * PHP port + modifications (c) 2012 Shish <webmaster@shishnet.org>
 *
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED.  IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS
 * OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION)
 * HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF
 * SUCH DAMAGE.
 */

/* 
 * configurable bits
 */
define("SPAWN", "/home/shish/bin/spawn-fcgi -s /tmp/fcgi-demo.sock -d ".getcwd()." -- ./demo.py fastcgi");
define("SOCKET", "/tmp/fcgi-demo.sock");


/*
 * FastCGI constants
 */
define('FCGI_LISTENSOCK_FILENO', 0);

define('FCGI_HEADER_LEN', 8);

define('FCGI_VERSION_1', 1);

define('FCGI_BEGIN_REQUEST', 1);
define('FCGI_ABORT_REQUEST', 2);
define('FCGI_END_REQUEST', 3);
define('FCGI_PARAMS', 4);
define('FCGI_STDIN', 5);
define('FCGI_STDOUT', 6);
define('FCGI_STDERR', 7);
define('FCGI_DATA', 8);
define('FCGI_GET_VALUES', 9);
define('FCGI_GET_VALUES_RESULT', 10);
define('FCGI_UNKNOWN_TYPE', 11);
define('FCGI_MAXTYPE', FCGI_UNKNOWN_TYPE);

define('FCGI_NULL_REQUEST_ID', 0);

define('FCGI_KEEP_CONN', 1);

define('FCGI_RESPONDER', 1);
define('FCGI_AUTHORIZER', 2);
define('FCGI_FILTER', 3);

define('FCGI_REQUEST_COMPLETE', 0);
define('FCGI_CANT_MPX_CONN', 1);
define('FCGI_OVERLOADED', 2);
define('FCGI_UNKNOWN_ROLE', 3);

define('FCGI_MAX_CONNS', 'FCGI_MAX_CONNS');
define('FCGI_MAX_REQS', 'FCGI_MAX_REQS');
define('FCGI_MPXS_CONNS', 'FCGI_MPXS_CONNS');

# SHIT: PHP needs different patterns for encode and decode
define('FCGI_Header', 'CCnnCx');
define('FCGI_Header2', 'Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/x');
define('FCGI_BeginRequestBody', 'nCx5');
define('FCGI_EndRequestBody', 'NCx3');
define('FCGI_UnknownTypeBody', 'Cx7');

# SHIT: PHP has no calcsize; so we calculate by hand and hardcode the result
define('FCGI_BeginRequestBody_LEN', 8); # calcsize(FCGI_BeginRequestBody));
define('FCGI_EndRequestBody_LEN', 8); # calcsize(FCGI_EndRequestBody));
define('FCGI_UnknownTypeBody_LEN', 8); # calcsize(FCGI_UnknownTypeBody));

/*
 * Decodes a name/value pair.
 * 
 * The number of bytes decoded as well as the name/value pair
 * are returned.
 */
function decode_pair($s, $pos=0) {
    $nameLength = ord($s[$pos]);
    if($nameLength & 128) {
        $nameLength = unpack('N', substr($s, $pos, 4));
		$nameLength = $nameLength[0] & 0x7fffffff;
        $pos += 4;
	}
    else {
        $pos += 1;
	}

    $valueLength = ord($s[$pos]);
    if($valueLength & 128) {
        $valueLength = unpack('N', substr($s, $pos, 4));
		$valueLength = $valueLength[0] & 0x7fffffff;
        $pos += 4;
	}
    else {
        $pos += 1;
	}

    $name = substr($s, $pos, $nameLength);
    $pos += $nameLength;
    $value = substr($s, $pos, $valueLength);
    $pos += $valueLength;

    return array($pos, array($name, $value));
}

/*
 * Encodes a name/value pair.
 *
 * The encoded string is returned.
 */
function encode_pair($name, $value) {
    $nameLength = strlen($name);
    if($nameLength < 128) {
        $s = chr($nameLength);
	}
    else {
        $s = pack('N', $nameLength | 0x80000000);
	}

    $valueLength = strlen($value);
    if($valueLength < 128) {
        $s .= chr($valueLength);
	}
    else {
        $s .= pack('N', $valueLength | 0x80000000);
	}

    return $s . $name . $value;
}

function spawn() {
	#print "Spawning new FastCGI daemon\n";
	$r = shell_exec(SPAWN);
	if($r == "") {
		die("Couldn't spawn FastCGI daemon");
	}
}

/*
 * A FastCGI Record.
 * 
 * Used for encoding/decoding records.
 */
class Record {
    function __construct($type=FCGI_UNKNOWN_TYPE, $requestId=FCGI_NULL_REQUEST_ID) {
        $this->version = FCGI_VERSION_1;
        $this->type = $type;
        $this->requestId = $requestId;
        $this->contentLength = 0;
        $this->paddingLength = 0;
        $this->contentData = '';
	}

    /*
	 * Attempts to receive length bytes from a socket, blocking if necessary.
	 * (Socket may be blocking or non-blocking.)
     */
    function _recvall($sock, $length) {
        $dataList = array();
        $recvLen = 0;
        while($length) {
			$d = socket_recv($sock, $data, $length, null);
			if($d === false) {
				$r = array($sock);
				$w = array();
				$x = array();
				socket_select($r, $w, $x, NULL);
				continue;
			}
            if(!$data) {
                break;
			}
            $dataList[] = $data;
            $dataLen = strlen($data);
            $recvLen += $dataLen;
            $length -= $dataLen;
		}
        return array(implode('', $dataList), $recvLen);
	}

	/*
	 * Read and decode a Record from a socket.
	 */
    function read($sock) {
        try {
			$parts = $this->_recvall($sock, FCGI_HEADER_LEN);
            $header = $parts[0];
			$length = $parts[1];
		}
        catch(Exception $e) {
            throw new EOFError();
		}

        if($length < FCGI_HEADER_LEN) {
            throw new EOFError();
		}
        
		$parts = unpack(FCGI_Header2, $header);
        $this->version = $parts['version'];
		$this->type = $parts['type'];
		$this->requestId = $parts['requestId'];
		$this->contentLength = $parts['contentLength'];
		$this->paddingLength = $parts['paddingLength'];

        if($this->contentLength) {
            try {
				$parts = $this->_recvall($sock, $this->contentLength);
                $this->contentData = $parts[0];
				$length = $parts[1];
			}
            catch(Exception $e) {
                throw new EOFError();
			}

            if($length < $this->contentLength) {
                throw new EOFError();
			}
		}

        if($this->paddingLength) {
            try {
                $this->_recvall($sock, $this->paddingLength);
			}
            catch(Exception $e) {
                throw new EOFError();
			}
		}
	}

	/*
	 * Writes data to a socket and does not return until all the data is sent.
	 */
    function _sendall($sock, $data) {
        $length = strlen($data);
        while($length) {
			$sent = socket_send($sock, $data, strlen($data), 0);
			if($sent === FALSE) {
				$errorcode = socket_last_error();
				#if($errorcode == 107) { # ENOTCONN
				#	spawn();
				#	continue;
				#}
				#else if($e[0] == errno.EAGAIN) {
				#	#select.select([], [sock], [])
				#	continue;
				#}
				#else {
					$errormsg = socket_strerror($errorcode);
					die("Couldn't write to socket: [$errorcode] $errormsg");
				#}
			}
            $data = substr($data, $sent);
            $length -= $sent;
		}
	}

	/*
	 * Encode and write a Record to a socket.
	 */
    function write($sock) {
        $this->paddingLength = -$this->contentLength & 7;

        $header = pack(FCGI_Header, $this->version, $this->type,
                             $this->requestId, $this->contentLength,
                             $this->paddingLength);
        $this->_sendall($sock, $header);
        if($this->contentLength) {
            $this->_sendall($sock, $this->contentData);
		}
        if($this->paddingLength) {
            $this->_sendall($sock, str_repeat("\x00", $this->paddingLength));
		}
	}
}

function _fcgiParams($sock, $requestId, $params) {
    $rec = new Record(FCGI_PARAMS, $requestId);
    $data = array();
    foreach($params as $name => $value) {
        $data[] = encode_pair($name, $value);
	}
    $data = implode('', $data);
    $rec->contentData = $data;
    $rec->contentLength = strlen($data);
    $rec->write($sock);
}

function connect() {
	$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);

	$connected = false;
	$errors = 0;
	while(!$connected && $errors < 5) {
		$connected = socket_connect($sock, SOCKET);
		if($connected === FALSE) {
			$errorcode = socket_last_error();
			# no socket, or first connection refusal
			if($errorcode == 2 || ($errorcode == 111 && $errors == 0)) {
				spawn();
			}
			# ECONNREFUSED; we started a process but it isn't running yet
			else if($errorcode == 111) {
				sleep(1);
				$errors++;
			}
			else {
				$errormsg = socket_strerror($errorcode);
				die("Couldn't write to socket: [$errorcode] $errormsg");
			}
		}
	}
	if(!$connected) {
		die("FastCGI daemon didn't start accepting connections");
	}

	return $sock;
}

function pass_request() {
	$sock = connect();

	$env = array();
	foreach($_SERVER as $k => $v) {
		$env[$k] = $v;
	}

    $requestId = 1;

    # Begin the request
    $rec = new Record(FCGI_BEGIN_REQUEST, $requestId);
    $rec->contentData = pack(FCGI_BeginRequestBody, FCGI_RESPONDER, 0);
    $rec->contentLength = FCGI_BeginRequestBody_LEN;
    $rec->write($sock);

    _fcgiParams($sock, $requestId, $env);
    _fcgiParams($sock, $requestId, array());

    # Transfer wsgi.input to FCGI_STDIN
    $content_length = isset($env['CONTENT_LENGTH']) ? (int)$env['CONTENT_LENGTH'] : 0;
    $s = '';
	$fp = fopen("php://input", "r");
    while(True) {
        $chunk_size = min($content_length, 4096);
		$s = fread($fp, $chunk_size);
        if(!$s) {
			break;
		}

        $content_length -= strlen($s);
        $rec = new Record(FCGI_STDIN, $requestId);
        $rec->contentData = $s;
        $rec->contentLength = strlen($s);
        $rec->write($sock);
	}
	fclose($fp);

    # Empty FCGI_DATA stream
    $rec = new Record(FCGI_DATA, $requestId);
    $rec->write($sock);

    # Main loop. Process FCGI_STDOUT, FCGI_STDERR, FCGI_END_REQUEST
    # records from the application.
    $result = array();
    $err = '';
    while(True) {
        $inrec = new Record();
        $inrec->read($sock);
        if($inrec->type == FCGI_STDOUT) {
            if($inrec->contentData) {
                $result[] = $inrec->contentData;
			}
            else {
                # TODO: Should probably be pedantic and no longer
                # accept FCGI_STDOUT records?"
			}
		}
        else if($inrec->type == FCGI_STDERR) {
            # Simply forward to wsgi.errors
            $err .= $inrec->contentData;
            #environ['wsgi.errors'].write(inrec.contentData)
		}
        else if($inrec->type == FCGI_END_REQUEST) {
            # TODO: Process appStatus/protocolStatus fields?
            break;
		}
	}

    # Done with this transport socket, close it. (FCGI_KEEP_CONN was not
    # set in the FCGI_BEGIN_REQUEST record we sent above. So the
    # application is expected to do the same.)
    socket_close($sock);

    $result = implode('', $result);

    # Parse response headers from FCGI_STDOUT
    $status = '200 OK';
    $headers = array();
    $pos = 0;
    while(True) {
        $eolpos = strpos($result, "\n", $pos);
        if($eolpos < 0) {
			break;
		}
        $line = substr($result, $pos, $eolpos-$pos-1);
        $pos = $eolpos + 1;

        # strip in case of CR. NB: This will also strip other
        # whitespace...
        $line = trim($line);
        
        # Empty line signifies end of headers
        if(trim($line) == "") {
			break;
		}

        # TODO: Better error handling
		$parts = explode(":", $line, 2);
        $header = $parts[0];
		$value = $parts[1];
        $header = strtolower(trim($header));
        $value = trim($value);

        if($header == 'status') {
            # Special handling of Status header
            $status = $value;
            if(strpos($status, ' ') < 0) {
                # Append a dummy reason phrase if one was not provided
                $status .= ' FCGIApp';
			}
		}
        else {
            $headers[] = array($header, $value);
		}
	}

    $result = substr($result, $pos);

    # FIXME: do something with $status?
    return array($headers, $result);
}

$req = pass_request();

foreach($req[0] as $header) {
	header($header[0], $header[1]);
}

print $req[1];
?>
