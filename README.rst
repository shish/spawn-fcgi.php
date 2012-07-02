Spawn-FCGI.php
~~~~~~~~~~~~~~
A script to translate PHP requests into FastCGI requests, thus allowing
hosting of FastCGI apps (good) on any PHP-compatible web host (cheap, common).

The auto-spawning feature requires that PHP have the shell_exec feature enabled

Currently only Unix sockets are supported as FastCGI transport; TCP sockets
shouldn't be too hard to add.

Configuration
-------------
Change the SPAWN define to be a command which creates a daemon process and a
Unix socket.

Change the SOCKET define to point at said socket.

Credits
-------
This is almost a direct port of Flup's FastCGI test harness,
flup_fcgi_client.py (c) 2006 Allan Saddi <allan@saddi.com> +
2011 Vladimir Rusinov <vladimir@greenmice.info>

Modified to take request data from the environment rather than
from function parameters.

PHP port + modifications (c) 2012 Shish <webmaster@shishnet.org>
