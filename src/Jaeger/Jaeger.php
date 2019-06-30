<?php

namespace Jaeger;

use Jaeger\Sampler\Sampler;
use OpenTracing\SpanContext;
use OpenTracing\Formats;
use OpenTracing\Tracer;
use Jaeger\Reporter\Reporter;
use OpenTracing\StartSpanOptions;
use OpenTracing\Reference;
use Jaeger\Propagator\Propagator;

class Jaeger implements Tracer{

    private $reporter = null;

    private $sampler = null;

    private $gen128bit = false;

    private $scopeManager;

    public static $handleProto = null;

    public $spans = [];

    public $tags = [];

    public $process = null;

    public $procesSize = 0;

    public $serverName = '';

    public $bufferSize = '';

    public $processThrift = '';

    public $spanThrifts = [];

    public $propagator = null;

    public function __construct($serverName = '', Reporter $reporter, Sampler $sampler,
                                ScopeManager $scopeManager){

        $this->reporter = $reporter;

        $this->sampler = $sampler;

        $this->scopeManager = $scopeManager;

        $this->setTags($this->sampler->getTags());
        $this->setTags($this->getEnvTags());

        if($serverName == '') {
            $this->serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'unknow server';
        }else{
            $this->serverName = $serverName;
        }
    }


    /**
     * @param array $tags  key => value
     */
    public function setTags(array $tags = []){
        if(!empty($tags)) {
            $this->tags = array_merge($this->tags, $tags);
        }
    }


    /**
     * init span info
     * @param string $operationName
     * @param array $options
     * @return Span
     */
    public function startSpan($operationName, $options = []){

        if (!($options instanceof StartSpanOptions)) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if($parentSpan == null || !$parentSpan->traceIdLow){
            $low = $this->generateId();
            $spanId = $low;
            $flags = $this->sampler->IsSampled();
            $newSpan = new \Jaeger\SpanContext($spanId, 0, $flags, null, 0);
            $newSpan->traceIdLow = $low;
            if($this->gen128bit == true){
                $newSpan->traceIdHigh = $this->generateId();
            }
        }else{
            $newSpan = new \Jaeger\SpanContext($this->generateId(),
                $parentSpan->spanId, $parentSpan->flags, null, 0);
            $newSpan->traceIdLow = $parentSpan->traceIdLow;
            if($parentSpan->traceIdHigh){
                $newSpan->traceIdHigh = $parentSpan->traceIdHigh;
            }
        }

        $span = new Span($operationName, $newSpan, $options->getReferences());
        $span->setTags($options->getTags());

        if($newSpan->isSampled() == 1) {
            $this->spans[] = $span;
        }

        return $span;
    }


    public function setPropagator(Propagator $propagator){
        $this->propagator = $propagator;
    }


    /**
     * 注入
     * @param SpanContext $spanContext
     * @param string $format
     * @param $carrier
     */
    public function inject(SpanContext $spanContext, $format, &$carrier){
        if($format == Formats\TEXT_MAP){
            $this->propagator->inject($spanContext, $format, $carrier);
        }else{
            throw new \Exception("not support format");
        }
    }


    /**
     * 提取
     * @param string $format
     * @param $carrier
     */
    public function extract($format, $carrier){
        if($format == Formats\TEXT_MAP){
            return $this->propagator->extract($format, $carrier);
        }else{
            throw new \Exception("not support format");
        }
    }


    public function getSpans(){
        return $this->spans;
    }


    public function reportSpan(){
        if($this->spans) {
            $this->reporter->report($this);
            $this->spans = [];
        }
    }


    public function getScopeManager(){
        return $this->scopeManager;
    }


    public function getActiveSpan(){
        $activeScope = $this->getScopeManager()->getActive();
        if ($activeScope === null) {
            return null;
        }

        return $activeScope->getSpan();
    }


    public function startActiveSpan($operationName, $options = []){
        if (!$options instanceof StartSpanOptions) {
            $options = StartSpanOptions::create($options);
        }

        $parentSpan = $this->getParentSpanContext($options);
        if ($parentSpan === null && $this->getActiveSpan() !== null) {
            $parentContext = $this->getActiveSpan()->getContext();
            $options = $options->withParent($parentContext);
        }

        $span = $this->startSpan($operationName, $options);
        return $this->getScopeManager()->activate($span, $options->shouldFinishSpanOnClose());
    }


    private function getParentSpanContext(StartSpanOptions $options)
    {
        $references = $options->getReferences();
        foreach ($references as $ref) {
            if ($ref->isType(Reference::CHILD_OF)) {
                return $ref->getContext();
            }
        }

        return null;
    }


    public function getEnvTags(){
        $tags = [];
        if(isset($_SERVER['JAEGER_TAGS']) && $_SERVER['JAEGER_TAGS'] != ''){
            $envTags = explode(',', $_SERVER['JAEGER_TAGS']);
            foreach ($envTags as $envK => $envTag){
                list($key, $value) = explode('=', $envTag);
                $tags[$key] = $value;
            }
        }

        return $tags;
    }


    public function gen128bit(){
        $this->gen128bit = true;
    }


    /**
     * 结束,发送信息到jaeger
     */
    public function flush(){
        $this->reportSpan();
        $this->reporter->close();
    }


    private function generateId(){
        return microtime(true) * 10000 . rand(10000, 99999);
    }
}


?>