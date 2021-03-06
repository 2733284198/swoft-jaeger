<?php
declare(strict_types=1);


namespace ExtraSwoft\Jaeger\Transport;


use Jaeger\Jaeger;
use Jaeger\Thrift\JaegerThriftSpan;
use Jaeger\Thrift\Process;
use Jaeger\Thrift\Span;
use Jaeger\Thrift\TStruct;
use Jaeger\Transport\Transport;
use Jaeger\Transport\TransportUdp;
use Jaeger\UdpClient;
use Swoft\App;
use Thrift\Transport\TMemoryBuffer;
use Thrift\Protocol\TCompactProtocol;
use Jaeger\Constants;

class JaegerTransportLog implements Transport
{

    private $tran = null;

    // sizeof(Span) * numSpans + processByteSize + emitBatchOverhead <= maxPacketSize
    public static $maxSpanBytes = 0;

    public static $batchs = [];

    public $thriftProtocol = null;

    public $procesSize = 0;

    public $bufferSize = 0;

    public function __construct($maxPacketSize = '')
    {

        if($maxPacketSize == 0){
            $maxPacketSize = Constants\UDP_PACKET_MAX_LENGTH;
        }

        self::$maxSpanBytes = $maxPacketSize - Constants\EMIT_BATCH_OVER_HEAD;

        $this->tran = new TMemoryBuffer();
        $this->thriftProtocol = new TCompactProtocol($this->tran);
    }


    public function buildAndCalcSizeOfProcessThrift(Jaeger $jaeger){
        $jaeger->processThrift = (new JaegerThriftSpan())->buildJaegerProcessThrift($jaeger);
        $jaeger->process = (new Process($jaeger->processThrift));
        $this->procesSize = $this->getAndCalcSizeOfSerializedThrift($jaeger->process, $jaeger->processThrift);
        $this->bufferSize += $this->procesSize;
    }


    /**
     * 收集将要发送的追踪信息
     * @param Jaeger $jaeger
     * @return bool
     */
    public function append(Jaeger $jaeger){

        if($jaeger->process == null){
            $this->buildAndCalcSizeOfProcessThrift($jaeger);
        }
        if($this->bufferSize == $this->procesSize) {
            $jaeger->spanThrifts = [];
        }

        foreach($jaeger->spans as $span){

            $spanThrift = (new JaegerThriftSpan())->buildJaegerSpanThrift($span);

            $agentSpan = Span::getInstance();
            $agentSpan->setThriftSpan($spanThrift);
            $spanSize = $this->getAndCalcSizeOfSerializedThrift($agentSpan, $spanThrift);

            if($spanSize > self::$maxSpanBytes){
                //throw new \Exception("Span is too large");
                continue;
            }

            $this->bufferSize += $spanSize;
            if($this->bufferSize > self::$maxSpanBytes){
                $jaeger->spanThrifts[] = $spanThrift;
                self::$batchs[] = ['thriftProcess' => $jaeger->processThrift
                    , 'thriftSpans' => $jaeger->spanThrifts];

                $this->flush();
                $jaeger->spanThrifts = [];
            }else{
                $jaeger->spanThrifts[] = $spanThrift;
            }
        }

        self::$batchs[] = ['thriftProcess' => $jaeger->processThrift
            , 'thriftSpans' => $jaeger->spanThrifts];

        return true;
    }


    public function resetBuffer(){
        $this->bufferSize = $this->procesSize;
        self::$batchs = [];
    }


    /**
     * 获取序列化后的thrift和计算序列化后的thrift字符长度
     * @param TStruct $ts
     * @param $serializedThrift
     * @return mixed
     */
    private function getAndCalcSizeOfSerializedThrift(TStruct $ts, &$serializedThrift){

        $ts->write($this->thriftProtocol);
        $serThriftStrlen = $this->tran->available();
        //获取后buf清空
        $serializedThrift['wrote'] = $this->tran->read(Constants\UDP_PACKET_MAX_LENGTH);

        return $serThriftStrlen;
    }


    /**
     * @return int
     */
    public function flush(){
        $batchNum = count(self::$batchs);
        if ($batchNum <= 0) {
            return 0;
        }

//        $logFile = $this->getTimedFilename();
//        if (!file_exists($logFile)) {
//            $file = fopen($logFile, "w");
//            fclose($file);
//            chmod($logFile, 0777);
//        }

        $spanNum = 0;
        foreach (self::$batchs as $batch){
            $spanNum += count($batch['thriftSpans']);
            $udp = new LogClient($this->getTimedFilename());
            $udp->emitBatch($batch);
            $udp->close();
        }

        $this->resetBuffer();
        return $spanNum;
    }

    protected function getTimedFilename()
    {
        $logFile = App::getAlias('@log') . '/jaeger.log';
        $fileInfo = pathinfo($logFile);
        $timedFilename = str_replace(
            array('{filename}', '{date}'),
            array($fileInfo['filename'], date('Y-m-d-H')),
            $fileInfo['dirname'] . '/' . '{filename}-{date}'
        );

        if (!empty($fileInfo['extension'])) {
            $timedFilename .= '.'.$fileInfo['extension'];
        }

        return $timedFilename;
    }

}