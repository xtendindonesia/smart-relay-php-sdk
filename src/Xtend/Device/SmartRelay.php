<?php
/**
 * @license   http://opensource.org/licenses/BSD-3-Clause BSD-3-Clause
 * @copyright Copyright (c) 2014-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @author    Dolly Aswin <dolly.aswin@gmail.com>
 */

declare(strict_types = 1);

namespace Xtend\Device;

use RuntimeException;
use function socket_set_option;
use function socket_last_error;
use function socket_strerror;
use function socket_connect;
use function socket_create;
use function socket_close;
use function socket_send;
use function socket_recv;
use function in_array;
use function str_pad;
use function hex2bin;
use function dechex;
use function hexdec;
use function strlen;
use function pow;
use \SO_RCVTIMEO;
use \SOL_SOCKET;
use \MSG_PEEK;

class SmartRelay
{
    const PIN_OFF = 0;

    const PIN_ON  = 1;

    const DEFAULT_DEVICE_ID = '01'; // hexadecimal

    /**
     * @string
     */
    private $host;

    /**
     * @int
     */
    private $port;

    /**
     * @bool
     */
    private $conStatus = false;

    /**
     * @Socket 
     */
    private $socket = null;

    /**
     * @int 
     */
    private $maxResponseLen = 20480; 

    /**
     * @int 
     */
    private $maxSocketRecTimeout = 1;

    /**
     * @param string $host
     * @param int    $port
     *
     * @void
     */
    public function __construct(string $host, int $port = 50000)
    {
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * Open Socket
     *
     * @void
     */
    public function open()
    {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, 0);
        $this->conStatus = @socket_connect($this->socket, $this->host, $this->port);
        socket_set_option($this->socket, \SOL_SOCKET, \SO_RCVTIMEO, ['sec' => $this->maxSocketRecTimeout, 'usec' => 0]);
        
        if (!$this->conStatus) {
            throw new RuntimeException('Unable to connect to Xtend Smart Relay');
        }
    }

    /**
     * Get Connection Status
     *
     * @return boolean 
     */
    public function isConnect(): bool
    {
        return $this->conStatus;
    }

    /**
     * Get Socket Connection
     *
     * @return Socket 
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Close Socket Connection
     *
     * @void 
     */
    public function close()
    {
        socket_close($this->socket);
    }

    /**
     * Create Control Bit
     *
     * @param  int $pin
     * @param  int $value
     *
     * @return array of values in hexadecimal
     */
    static private function createControlBit(int $pin, int $value): array
    {
        $controlBit = ['00', '00']; // default off
        $strPin = (string) $pin;

        // set control bit
        if ($value == self::PIN_ON) {
            $controlBit[1] = str_pad($strPin, 2, '0', STR_PAD_LEFT); // turn on pin
        }

        return $controlBit;
    }

    /**
     * Create Enable Bit
     *
     * @param  int $pin
     *
     * @return array of values in hexadecimal
     */
    static private function createEnableBit(int $pin): array
    {
        $strPin = (string) $pin;
        return ['00', str_pad($strPin, 2, '0', STR_PAD_LEFT)];
    }

    /**
     * Sum Command List
     *
     * @param  array $commandList
     *
     * @return int
     */
    static private function sumCommandList(array $hexCommandList): int
    {
        // get decimal value from hexadecimal command list
        $decCommandList = array_map(function ($v) {
                              return hexdec($v);
                          }, $hexCommandList);
    
        // get total value value from commands
        return array_sum($decCommandList);
    }

    /**
     * Create Check Sums
     *
     * @param  array $commandList
     *
     * @return array
     */
    static private function createCheckSums(array $hexCommandList): array
    {
        $sumDecCommand = self::sumCommandList($hexCommandList);
        $decCheckSum1  = $sumDecCommand & hexdec('FF');
        $decCheckSum2  = ($decCheckSum1 + $decCheckSum1) & hexdec('FF');

        return [dechex($decCheckSum1), dechex($decCheckSum2)];
    }

    /**
     * Compose Command For Writing to Device Socket
     *
     * @param int       $pin 
     * @param int       $value 
     * @param int|null  $deviceId 
     *
     * @return command string
     */
    static private function getWriteCommand(int $pin, int $value, string $deviceId = self::DEFAULT_DEVICE_ID)
    {
        $prefCommand = 'ccdda1'; // prefix command for writing in hexa
        $commandList   = [];
        $commandList[] = $prefCommand;
        $commandList[] = $deviceId;
    
        // set pin
        $pin = pow(2, $pin - 1); // convert decimal into bit
    
        $controlBit = self::createControlBit($pin, $value);
        $commandList[] = $controlBit[0];
        $commandList[] = $controlBit[1];
    
        $enableBit  = self::createEnableBit($pin);
        $commandList[] = $enableBit[0];
        $commandList[] = $enableBit[1];
        // print_r($commandList);
    
        $checkSums = self::createCheckSums($commandList);
        $commandList[] = $checkSums[0];
        $commandList[] = $checkSums[1];
        // print_r($commandList);
    
        return implode('', $commandList);
    }

    /**
     * Push Command to SmartRelay
     *
     * @param int       $pin 
     * @param int       $value 
     * @param int|null  $deviceId 
     *
     * @return response from device when this method works 
     * @throw  \RuntimeException
     */
    public function push(int $pin, int $value, string $deviceId = self::DEFAULT_DEVICE_ID)
    {
        $hexCommand = self::getWriteCommand($pin, $value, $deviceId);
        // var_dump($hexCommand);
        $binCommand = hex2bin($hexCommand); // convert comment to binary
        if (socket_send($this->getSocket(), $binCommand, strlen($hexCommand), 0) === false) {
            $sockErrCode = socket_last_error();
            throw new RuntimeException('Error ' . $sockErrCode . ': ' . socket_strerror($sockErrCode));
        }

        $response = null;
        if (socket_recv($this->getSocket(), $response, $this->maxResponseLen, MSG_PEEK) === false) {
            $sockErrCode = socket_last_error();
            throw new RuntimeException('Error ' . $sockErrCode . ': ' . socket_strerror($sockErrCode));
        }

        return $response;
    }

    /**
     * Set Options
     *
     * @param array $options
     *
     * @void
     */
    public function setOptions(array $options)
    {
        $keyOptions = ['max_response_len', 'max_socket_rec_timeout'];
        foreach ($options as $key => $value) {
            if (in_array($key, $keyOptions)) {
                continue;
            }

            switch ($key) {
                case 'max_response_len':
                    $this->maxResponseLen = $value;
                    break;
                case 'max_socket_rec_timeout':
                    $this->maxSocketRecTimeout = $value;
                    break;
            }
        }
    }
}
