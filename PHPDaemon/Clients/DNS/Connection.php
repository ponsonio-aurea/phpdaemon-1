<?php
namespace PHPDaemon\Clients\DNS;

use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;

/**
 * @package    NetworkClients
 * @subpackage DNSClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection
{
    /**
     * @TODO DESCR
     */
    const STATE_PACKET = 1;
    /**
     * @var array Response
     */
    public $response = [];
    /**
     * @var integer Sequence
     */
    protected $seq = 0;
    /**
     * @var boolean Current packet size
     */
    protected $pctSize = 0;

    /**
     * @var integer Default low mark. Minimum number of bytes in buffer.
     */
    protected $lowMark = 2;

    /**
     * @var integer Default high mark. Maximum number of bytes in buffer.
     */
    protected $highMark = 512;

    protected $rcodeMessages = [
        0 => 'Success',
        1 => 'Format Error',
        2 => 'Server Failure',
        3 => 'Non-Existent Domain',
        4 => 'Not Implemented',
        5 => 'Query Refused',
        6 => 'Name Exists when it should not',
        7 => 'RR Set Exists when it should not',
        8 => 'RR Set that should exist does not',
        9 => 'Not Authorized',
        10 => 'Name not contained in zone',
        16 => 'TSIG Signature Failure',
        17 => 'Key not recognized',
        18 => 'Signature out of time window',
        19 => 'Bad TKEY Mode',
        20 => 'Duplicate key name',
        21 => 'Algorithm not supported',
        22 => 'Bad Truncation',
        23 => 'Bad/missing server cookie'
    ];

    /**
     * Called when new data received
     * @return void
     */
    public function onRead()
    {
        start:
        if ($this->type === 'udp') {
            $this->onUdpPacket($this->read($this->getInputLength()));
            return;
        }
        if ($this->state === self::STATE_ROOT) {
            if (false === ($hdr = $this->readExact(2))) {
                return; // not enough data
            }
            $this->pctSize = Binary::bytes2int($hdr);
            $this->setWatermark($this->pctSize);
            $this->state = self::STATE_PACKET;
        }
        if ($this->state === self::STATE_PACKET) {
            if (false === ($pct = $this->readExact($this->pctSize))) {
                return; // not enough data
            }
            $this->state = self::STATE_ROOT;
            $this->setWatermark(2);
            $this->onUdpPacket($pct);
        }
        goto start;
    }

    /**
     * Called when new UDP packet received.
     * @param  string $pct
     * @return void
     */
    public function onUdpPacket($pct)
    {
        if (mb_orig_strlen($pct) < 10) {
            return;
        }
        $orig = $pct;
        $this->response = [];
        Binary::getWord($pct); // ID
        $bitmap = Binary::getBitmap(Binary::getByte($pct)) . Binary::getBitmap(Binary::getByte($pct));
        //$qr = (int) $bitmap[0];
        $opcode = bindec(substr($bitmap, 1, 4));
        //$aa = (int) $bitmap[5];
        //$tc = (int) $bitmap[6];
        //$rd = (int) $bitmap[7];
        //$ra = (int) $bitmap[8];
        //$z = bindec(substr($bitmap, 9, 3));
        $rcode = bindec(mb_orig_substr($bitmap, 12));
        $this->response['status'] = [
            'rcode' => $rcode,
            'msg' => $this->getMessageByRcode($rcode)
        ];
        $qdcount = Binary::getWord($pct);
        $ancount = Binary::getWord($pct);
        $nscount = Binary::getWord($pct);
        $arcount = Binary::getWord($pct);
        for ($i = 0; $i < $qdcount; ++$i) {
            $name = Binary::parseLabels($pct, $orig);
            $typeInt = Binary::getWord($pct);
            $type = isset(Pool::$type[$typeInt]) ? Pool::$type[$typeInt] : 'UNK(' . $typeInt . ')';
            $classInt = Binary::getWord($pct);
            $class = isset(Pool::$class[$classInt]) ? Pool::$class[$classInt] : 'UNK(' . $classInt . ')';
            if (!isset($this->response[$type])) {
                $this->response[$type] = [];
            }
            $record = [
                'name' => $name,
                'type' => $type,
                'class' => $class,
            ];
            $this->response['query'][] = $record;
        }
        $getResRecord = function (&$pct) use ($orig) {
            $name = Binary::parseLabels($pct, $orig);
            $typeInt = Binary::getWord($pct);
            $type = isset(Pool::$type[$typeInt]) ? Pool::$type[$typeInt] : 'UNK(' . $typeInt . ')';
            $classInt = Binary::getWord($pct);
            $class = isset(Pool::$class[$classInt]) ? Pool::$class[$classInt] : 'UNK(' . $classInt . ')';
            $ttl = Binary::getDWord($pct);
            $length = Binary::getWord($pct);
            $data = mb_orig_substr($pct, 0, $length);
            $pct = mb_orig_substr($pct, $length);

            $record = [
                'name' => $name,
                'type' => $type,
                'class' => $class,
                'ttl' => $ttl,
            ];

            if (($type === 'A') || ($type === 'AAAA')) {
                if ($data === "\x00") {
                    $record['ip'] = false;
                    $record['ttl'] = 5;
                } else {
                    $record['ip'] = inet_ntop($data);
                }
            } elseif ($type === 'NS') {
                $record['ns'] = Binary::parseLabels($data, $orig);
            } elseif ($type === 'CNAME') {
                $record['cname'] = Binary::parseLabels($data, $orig);
            } elseif ($type === 'SOA') {
                $record['mname'] = Binary::parseLabels($data, $orig);
                $record['rname'] = Binary::parseLabels($data, $orig);
                $record['serial'] = Binary::getDWord($data);
                $record['refresh'] = Binary::getDWord($data);
                $record['retry'] = Binary::getDWord($data);
                $record['expire'] = Binary::getDWord($data);
                $record['nx'] = Binary::getDWord($data);
            } elseif ($type === 'MX') {
                $record['preference'] = Binary::getWord($data);
                $record['exchange'] = Binary::parseLabels($data, $orig);
            } elseif ($type === 'TXT') {
                $record['text'] = '';
                $lastLength = -1;
                while (($length = mb_orig_strlen($data)) > 0 && ($length !== $lastLength)) {
                    $record['text'] .= Binary::parseLabels($data, $orig);
                    $lastLength = $length;
                }
            } elseif ($type === 'SRV') {
                $record['priority'] = Binary::getWord($data);
                $record['weight'] = Binary::getWord($data);
                $record['port'] = Binary::getWord($data);
                $record['target'] = Binary::parseLabels($data, $orig);
            }

            return $record;
        };
        for ($i = 0; $i < $ancount; ++$i) {
            $record = $getResRecord($pct);
            if (!isset($this->response[$record['type']])) {
                $this->response[$record['type']] = [];
            }
            $this->response[$record['type']][] = $record;
        }
        for ($i = 0; $i < $nscount; ++$i) {
            $record = $getResRecord($pct);
            if (!isset($this->response[$record['type']])) {
                $this->response[$record['type']] = [];
            }
            $this->response[$record['type']][] = $record;
        }
        for ($i = 0; $i < $arcount; ++$i) {
            $record = $getResRecord($pct);
            if (!isset($this->response[$record['type']])) {
                $this->response[$record['type']] = [];
            }
            $this->response[$record['type']][] = $record;
        }
        $this->onResponse->executeOne($this->response);
        if (!$this->keepalive) {
            $this->finish();
            return;
        } else {
            $this->checkFree();
        }
    }

    /**
     * @param  int $rcode
     * @return string
     */
    protected function getMessageByRcode($rcode)
    {
        if (isset($this->rcodeMessages[$rcode])) {
            return $this->rcodeMessages[$rcode];
        } else {
            return 'UNKNOWN ERROR';
        }
    }

    /**
     * Gets the host information
     * @param  string $hostname Hostname
     * @param  callable $cb Callback
     * @callback $cb ( )
     * @return void
     */
    public function get($hostname, $cb)
    {
        $this->onResponse->push($cb);
        $this->setFree(false);
        $e = explode(':', $hostname, 3);
        $hostname = $e[0];
        $qtype = isset($e[1]) ? $e[1] : 'A';
        $qclass = isset($e[2]) ? $e[2] : 'IN';
        $QD = [];
        $qtypeInt = array_search($qtype, Pool::$type, true);
        $qclassInt = array_search($qclass, Pool::$class, true);

        if (($qtypeInt === false) || ($qclassInt === false)) {
            $cb(false);
            return;
        }

        $q = Binary::labels($hostname) . // domain
            Binary::word($qtypeInt) .
            Binary::word($qclassInt);

        $QD[] = $q;

        $packet = Binary::word(++$this->seq) . // Query ID
            Binary::bitmap2bytes(
                '0' . // QR = 0
                '0000' . // OPCODE = 0000 (standard query)
                '0' . // AA = 0
                '0' . // TC = 0
                '1' . // RD = 1
                '0' . // RA = 0,
                '000' . // reserved
                '0000', // RCODE
                2
            ) .
            Binary::word(sizeof($QD)) . // QDCOUNT
            Binary::word(0) . // ANCOUNT
            Binary::word(0) . // NSCOUNT
            Binary::word(0) . // ARCOUNT
            implode('', $QD);

        if ($this->type === 'udp') {
            $this->write($packet);
        } else {
            $this->write(Binary::word(mb_orig_strlen($packet)) . $packet);
        }
    }

    /**
     * Called when connection finishes
     * @return void
     */
    public function onFinish()
    {
        $this->onResponse->executeAll(false);
        parent::onFinish();
    }
}
