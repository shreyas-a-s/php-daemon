<?php


class Core_Plugin_Server implements Core_IPlugin
{
    const COMMAND_CONNECT       = 'CLIENT_CONNCT';
    const COMMAND_DISCONNECT    = 'CLIENT_DISCONNECT';
    const COMMAND_DESTRUCT      = 'SERVER_DISCONNECT';

    /**
     * @var Core_Daemon
     */
    public $daemon;

    /**
     * The IP Address server will listen on
     * @var string IP
     */
    public $ip;

    /**
     * The Port the server will listen on
     * @var integer
     */
    public $port;

    /**
     * The socket resource
     * @var Resource
     */
    public $socket;

    /**
     * Maximum number of concurrent clients
     * @var int
     */
    public $max_clients = 10;

    /**
     * Maximum bytes read from a given client connection at a time
     * @var int
     */
    public $max_read = 1024;

    /**
     * Array of stdClass client structs.
     * @var stdClass[]
     */
    public $clients = array();

    /**
     * Is this a Blocking server or a Polling server? When in blocking mode, the server will
     * wait for connections & commands indefinitely. When polling, it will look for any connections or commands awaiting
     * a response and return immediately if there aren't any.
     * @var bool
     */
    public $blocking = true;

    /**
     * Write verbose logging to the application log when true.
     * @var bool
     */
    public $debug = true;

    /**
     * Array of Command objects to match input against.
     * Note: In addition to input rec'd from the client, the server will emit the following commands when appropriate:
     * CLIENT_CONNECT(stdClass Client)
     * CLIENT_DISCONNECT(stdClass Client)
     * SERVER_DISCONNECT
     *
     * @var Core_Lib_Command[]
     */
    private $commands = array();


    public function __construct(Core_Daemon $daemon) {
        $this->daemon = $daemon;
    }

    public function __destruct() {
        unset($this->daemon);
    }

    /**
     * Called on Construct or Init
     * @return void
     */
    public function setup() {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if (!socket_bind($this->socket, $this->ip, $this->port))
            throw new Exception('Could not bind to address');

        socket_listen($this->socket);
    }

    /**
     * Called on Destruct
     * @return void
     */
    public function teardown() {
        // TODO: Implement teardown() method.
    }

    /**
     * This is called during object construction to validate any dependencies
     * NOTE: At a minimum you should ensure that if $errors is not empty that you pass it along as the return value.
     * @return Array  Return array of error messages (Think stuff like "GD Library Extension Required" or "Cannot open /tmp for Writing") or an empty array
     */
    public function check_environment(Array $errors = array()) {
        // TODO: Implement check_environment() method.
    }

    public function addCommand(Core_Lib_Command $command) {
        $this->commands[] = $command;
    }

    public function run() {


        $read[0] = $this->socket;
        foreach($this->clients as $client)
            $read[] = $client->socket;

        $result = socket_select($read, $write = null, $except = null, $this->blocking ? null : 1);
        if ($result === false) {
            $this->error('Socket Select Interruption: ' . socket_last_error());
            return false;
        }

        if ($result === 0) {
            if ($this->blocking)
                $this->error('Socket Select Interruption: ' . socket_last_error());
            else
                $this->log('Nothing waiting to be polled');
        }

        // If the master socket is in the read array, there's a pending connection
        if (in_array($this->socket, $read))
            $this->connect();


        foreach($this->clients as $slot => $client) {
            if (!in_array($client->socket, $read))
                continue;

            $input = socket_read($client->socket, $this->max_read);
            if ($input === null) {
                $this->disconnect($slot);
                continue;
            }

            $this->command($input);
        }
    }

    private function connect() {
        $slot = $this->slot();
        if ($slot === null)
            throw new Exception(sprintf('%s::%s Failed - Maximum number of connections has been reached.', __CLASS__, __METHOD__));

        $client = new stdClass();
        $client->socket = socket_accept($this->socket);
        if (empty($client->socket))
            throw new Exception(sprintf('%s::%s Failed - socket_accept failed with error: %s', __CLASS__, __METHOD__, socket_last_error()));

        socket_getpeername($client->socket, $client->ip);

        $client->write = function($string, $term = "\r\n") use($client) {
            if($term)
                $string .= $term;

            return socket_write($client->socket, $string, strlen($string));
        };

        $this->clients[$slot] = $client;
        $this->command(self::COMMAND_CONNECT, array($client));
    }

    private function command($input, Array $args = array()) {
        foreach($this->commands as $command)
            if($command->match($input, $args) && $command->exclusive)
                break;
    }

    private function disconnect($slot) {
        $this->command(self::COMMAND_DISCONNECT, array($this->clients[$slot]));
        @ socket_close($this->clients[$slot]->socket);
        unset($this->clients[$slot]);
    }

    private function slot() {
        for($i=0; $i < $this->max_clients; $i++ )
            if (empty($this->clients[$i]))
                return $i;

        return null;
    }

    private function debug($message) {
        if (!$this->debug)
            return;

        $this->daemon->log($message, 'SocketServer');
    }

    private function error($message) {
        $this->daemon->error($message, 'SocketServer');
    }

    private function log($message) {
        $this->daemon->log($message, 'SocketServer');
    }
}
