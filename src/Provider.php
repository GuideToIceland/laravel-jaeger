<?php namespace Ipunkt\LaravelJaeger;

use DB;
use Event;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Ipunkt\LaravelJaeger\Context\EmptyContext;
use Ipunkt\LaravelJaeger\Context\SpanContext;
use Ipunkt\LaravelJaeger\LogCleaner\LogCleaner;
use Jaeger\Client\ThriftClient;
use Jaeger\Id\IdGeneratorInterface;
use Jaeger\Id\RandomIntGenerator;
use Jaeger\Sampler\AdaptiveSampler;
use Jaeger\Sampler\ConstSampler;
use Jaeger\Sampler\OperationGenerator;
use Jaeger\Sampler\ProbabilisticSampler;
use Jaeger\Sampler\RateLimitingSampler;
use Jaeger\Sampler\SamplerInterface;
use Jaeger\Span\Factory\SpanFactory;
use Jaeger\Span\Factory\SpanFactoryInterface;
use Jaeger\Thrift\Agent\AgentClient;
use Jaeger\Transport\TUDPTransport;
use Log;
use Thrift\Protocol\TCompactProtocol;

/**
 * Class Provider
 * @package Ipunkt\LaravelJaeger
 */
class Provider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/config/jaeger.php', 'jaeger'
        );

        $this->publishes([
            __DIR__ . '/config/jaeger.php' => config_path('jaeger.php'),
        ]);


        $this->app->bind('jaeger-host', function() {
            $hostPort = explode(':', config('jaeger.host'));
            $host = $hostPort[0];
            if( empty($host) )
                return '127.0.0.1';

            return $host;
        });

        $this->app->bind('jaeger-port', function() {
            $hostPort = explode(':', config('jaeger.host'));

            return Arr::get($hostPort, 1, 6831);
        });

        $this->app->bind(SpanFactoryInterface::class, SpanFactory::class);
        $this->app->bind(IdGeneratorInterface::class, RandomIntGenerator::class);
        $this->bindSamplers();
        $this->chooseSampler();

        $this->app->bind(TCompactProtocol::class, function() {
            return new TCompactProtocol(app('jaeger-transport'));
        });
        $this->app->bind(TUDPTransport::class, function() {
            return new TUDPTransport(app('jaeger-host'), app('jaeger-port'));
        });
        $this->app->bind('jaeger-protocol', TCompactProtocol::class);
        $this->app->bind('jaeger-transport', TUDPTransport::class);
        $this->app->bind(AgentClient::class, function() {
            return new AgentClient( app('jaeger-protocol') );
        });
        $this->app->bind(ThriftClient::class, function() {
            return new ThriftClient( config('app.name'), app(AgentClient::class) );
        });

        $this->app->resolving(LogCleaner::class, function (LogCleaner $logCleaner) {
            $logCleaner
                ->setMaxLength(config('jaeger.log.max-string-length'))
                ->setCutoffIndicator(config('jaeger.log.cutoff-indicator'));
        });

        // Setup a unique ID for each request. This will allow us to find
        // the request trace in the jaeger ui
        $this->app->instance('context', app(EmptyContext::class));
    }

    public function boot()
    {
        $this->setupQueryLogging();

        if (app()->runningInConsole() && $this->disabledInConsole()) {
            return;
        }

        $this->app->instance('context', app(SpanContext::class));
        app('context')->start();


        $this->registerEvents();

        $this->parseRequest();
        $this->parseCommand();
    }

    protected function registerEvents(): void
    {
        // When the app terminates we must finish the global span
        // and send the trace to the jaeger agent.
        app()->terminating(function () {
            app('context')->finish();
        });

        // Listen for each logged message and attach it to the global span
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            app('context')->log((array)$e);
        });

        // Listen for the request handled event and set more tags for the trace
        Event::listen(RequestHandled::class, function (RequestHandled $e) {
            app('context')->setPrivateTags([
                'user_id' => optional(auth()->user())->id ?? "-",
                'company_id' => optional(auth()->user())->company_id ?? "-",

                'request_host' => $e->request->getHost(),
                'request_path' => $path = $e->request->path(),
                'request_method' => $e->request->method(),

                'api' => Str::contains($path, 'api'),
                'response_status' => $e->response->getStatusCode(),
                'error' => !$e->response->isSuccessful(),
            ]);
        });
    }

    private function setupQueryLogging()
    {

        // Also listen for queries and log then,
        // it also receives the log in the MessageLogged event above
        DB::listen(function ($query) {
            Log::debug("[DB Query] {$query->connection->getName()}", [
                'query' => str_replace('"', "'", $query->sql),
                'bindings' => $query->bindings,
                'time' => $query->time . 'ms',
            ]);
        });
    }

    private function parseRequest()
    {

        if (app()->runningInConsole()) {
            return;
        }

        app('context')->parse(request()->url(), request()->input());
    }

    private function parseCommand()
    {
        if (!app()->runningInConsole()) {
            return;
        }

        $currentArgs = request()->server('argv');
        $commandLine = implode(' ', $currentArgs);
        app('context')->parse($commandLine, []);
    }

    private function disabledInConsole()
    {
        return !config('jaeger.enable-for-console');
    }

    private function bindSamplers(): void
    {
        $this->app->bind(RateLimitingSampler::class, function () {
            return new RateLimitingSampler(config('jaeger.sampler-param'), app(OperationGenerator::class));
        });
        $this->app->bind(ProbabilisticSampler::class, function () {
            return new ProbabilisticSampler(config('jaeger.sampler-param'));
        });
        $this->app->bind(AdaptiveSampler::class, function() {
            return new AdaptiveSampler(app(RateLimitingSampler::class), app(ProbabilisticSampler::class));
        });
        $this->app->bind(ConstSampler::class, function() {
            return new ConstSampler(true);
        });
    }

    private function chooseSampler(): void
    {
        switch (config('jaeger.sampler')) {
            case 'probabilistic':
                $this->app->bind(SamplerInterface::class, ProbabilisticSampler::class);
                break;
            case 'rate-limiting':
                $this->app->bind(SamplerInterface::class, RateLimitingSampler::class);
                break;
            case 'adaptive':
                $this->app->bind(SamplerInterface::class, AdaptiveSampler::class);
                break;
            case 'const':
            default:
                $this->app->bind(SamplerInterface::class, ConstSampler::class);
                break;
        }
    }
}
