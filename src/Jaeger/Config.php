<?php
/*
 * Copyright (c) 2019, The Jaeger Authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 * in compliance with the License. You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software distributed under the License
 * is distributed on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express
 * or implied. See the License for the specific language governing permissions and limitations under
 * the License.
 */

namespace Jaeger;

use Jaeger\Propagator\JaegerPropagator;
use Jaeger\Propagator\ZipkinPropagator;
use Jaeger\Reporter\RemoteReporter;
use Jaeger\Reporter\Reporter;
use Jaeger\Sampler\ConstSampler;
use Jaeger\Sampler\Sampler;
use Jaeger\Transport\TransportUdp;
use OpenTracing\NoopTracer;
use OpenTracing\Tracer;

class Config
{
    /**
     * @var \Jaeger\Transport\Transport|null
     */
    private $transport = null;

    /**
     * @var \Jaeger\Reporter\Reporter|null
     */
    private $reporter = null;

    /**
     * @var \Jaeger\Sampler\Sampler|null
     */
    private $sampler = null;

    /**
     * @var \OpenTracing\ScopeManager|null
     */
    private $scopeManager = null;

    private $gen128bit = false;

    /**
     * @var Tracer|null
     */
    public static $tracer = null;

    /**
     * @var \OpenTracing\Span|null
     */
    public static $span = null;

    public static $instance = null;

    public static $disabled = false;

    public static $propagator = \Jaeger\Constants\PROPAGATOR_JAEGER;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function getInstance()
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * init jaeger, return can use flush  buffers.
     *
     * @param string $agentHostPort
     *
     * @throws \RuntimeException
     */
    public function initTracer(string $serviceName, $agentHostPort = ''): Tracer
    {
        if (self::$disabled) {
            return new NoopTracer();
        }

        if ('' == $serviceName) {
            throw new \RuntimeException('serviceName require');
        }

        if (isset(self::$tracer[$serviceName]) && !empty(self::$tracer[$serviceName])) {
            return self::$tracer[$serviceName];
        }

        if (null == $this->transport) {
            $this->transport = new TransportUdp($agentHostPort);
        }

        if (null == $this->reporter) {
            $this->reporter = new RemoteReporter($this->transport);
        }

        if (null == $this->sampler) {
            $this->sampler = new ConstSampler(true);
        }

        if (null == $this->scopeManager) {
            $this->scopeManager = new ScopeManager();
        }

        $tracer = new Jaeger($serviceName, $this->reporter, $this->sampler, $this->scopeManager);

        if (true == $this->gen128bit) {
            $tracer->gen128bit();
        }

        if (\Jaeger\Constants\PROPAGATOR_ZIPKIN == self::$propagator) {
            $tracer->setPropagator(new ZipkinPropagator());
        } else {
            $tracer->setPropagator(new JaegerPropagator());
        }

        self::$tracer[$serviceName] = $tracer;

        return $tracer;
    }

    public function setDisabled(bool $disabled)
    {
        self::$disabled = $disabled;

        return $this;
    }

    public function setTransport(Transport\Transport $transport)
    {
        $this->transport = $transport;

        return $this;
    }

    public function setReporter(Reporter $reporter)
    {
        $this->reporter = $reporter;

        return $this;
    }

    public function setSampler(Sampler $sampler)
    {
        $this->sampler = $sampler;

        return $this;
    }

    public function gen128bit()
    {
        $this->gen128bit = true;

        return $this;
    }

    public function flush()
    {
        if (count(self::$tracer) > 0) {
            foreach (self::$tracer as $tracer) {
                $tracer->reportSpan();
            }
            $this->reporter->close();
        }

        return true;
    }
}
