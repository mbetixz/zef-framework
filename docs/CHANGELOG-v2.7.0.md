# ZEF Framework v2.7.0 — Changelog Audit (asli dari monolith)

/*
 * ZEF FRAMEWORK v2.7.0 — EDGE-CASE AUDIT ROUND 2 (re-audit release)
 * ---------------------------------------------------------
 * Single-file / zero-composer fallback bundle.
 * Implements PSR-11, PSR-7, PSR-15 and PSR-17 contracts.
 *
 * PHP >= 8.4
 *
 * The PSR interfaces below are conditional compatibility shims. If the
 * official PSR packages are already loaded, ZEF reuses them rather than
 * redeclaring the interfaces.
 *
 * CHANGELOG v2.7.0 (second full edge-case audit over v2.6.0; every fix
 * verified by a runtime probe before it was applied)
 *
 * === HTTP / request target / URI ===
 *  1. splitRequestTarget(): parse_url() returns paths WITHOUT a leading
 *     "/" for asterisk-form/relative/opaque targets. "OPTIONS *" (legal per
 *     RFC 9112 §3.2 / RFC 9110 §9.3.7) built "http://example.com*" and then
 *     either threw (400 on a legal request) or silently corrupted the host.
 *     Non-slash-prefixed paths now normalize to "/"; the raw target is still
 *     preserved verbatim by getRequestTarget().
 *  2. protocolVersion(): 'HTTP/22' produced '22' which then failed protocol
 *     validation one layer deeper (400). Single-digit major only now; other
 *     garbage degrades to 1.1 consistently.
 *  3. SERVER_PORT non-numeric/0 no longer leaks into the base URL (Uri threw
 *     "Invalid port: 0"). Malformed values are ignored.
 *  4. Response reason phrase is validated (RFC 9110 §15.1: HTAB/SP/VCHAR/
 *     obs-text only). "OK\r\nInjected: 1" used to be stored verbatim.
 *  5. Uri::assertHost() now accepts '_' in reg-names (RFC 3986 §3.2.2;
 *     RFC 9110 Host = reg-name) — intranet hosts like my_host.example work.
 *  6. RequestFactory::fromServer() is now FULLY injectable: optional
 *     $query/$cookies/$parsedBody/$uploadedFiles parameters; previously
 *     $_GET/$_COOKIE/$_POST/$_FILES leaked through the "injectable" API.
 *
 * === Headers / uploads / emission ===
 *  7. Inbound header caps (resource exhaustion): ZEF_MAX_HEADER_COUNT (128),
     ZEF_MAX_HEADER_VALUE_BYTES (16384), ZEF_MAX_HEADERS_TOTAL_BYTES (65536);
     exceeded → PayloadTooLargeException (413-mapped), like the body policy.
 *  8. Header values are type-checked: null→'' / int→"123" / nested
 *     array→"Array" coercion replaced with InvalidHeaderException (PSR-7 §3).
 *  9. Emitter reconciles a lying Content-Length against the body stream and
 *     drops it on mismatch (CL.CL response-smuggling / client truncation,
     RFC 9110 §8.6). Out-of-range statuses and CRLF header values from
 *     foreign PSR-7 impls are re-validated and skipped (fail closed).
 * 10. Uploaded-file "ghosts" fixed: error=OK without tmp_name (or null
 *     error) previously produced a "valid" upload with the client-declared
 *     size and an empty body. Now downgraded to UPLOAD_ERR_NO_FILE; unknown
 *     string errors normalize there too. UploadedFile constructor rejects
 *     negative sizes (mirrors the factory).
 *
 * === Container / config / messaging / jobs ===
 * 11. ConfigAggregator: a provider calling get()/all()/merge() from
 *     getConfig() recursed until memory exhaustion (uncatchable OOM).
 *     Reentrant reads now throw a LogicException with a precise message.
 * 12. EventDispatcher implements PSR-14 stopPropagation: StoppableEvent
 *     events no longer invoke listeners after isPropagationStopped()
 *     (both pre-stopped and mid-chain stops were previously ignored).
 * 13. Dispatch depth guards (64) for EventDispatcher, CommandBus, QueryBus
 *     and InProcessMessageBus: re-entrant self-dispatch used to die with an
 *     uncatchable OOM fatal; now a LogicException.
 * 14. CommandBus idempotency: the event fan-out moved OUT of the cached
 *     producer. Previously a listener failure after a successful handler
 *     discarded the cached result and a client retry re-EXECUTED the command
 *     (double side effects). Replays never re-fire events.
 * 15. InProcessJobWorker: DLQ and retry enqueues are guarded — a full queue
 *     no longer throws out of execute() (breaking processOne()'s contract),
 *     aborts run(), and LOSES the destructively-dequeued job. Retry-enqueue
 *     failure dead-letters and reports a terminal JobResult instead.
 * 16. InProcessJobWorker rejects deadLetterQueue === queue (unobservable
 *     infinite re-DLQ loop). run() accepts an optional $onResult callback
 *     (JobResults were previously discarded entirely) and the idle poll
 *     path enforces a 1ms floor (pollIntervalMs=0 busy-spun at ~100% CPU).
 * 17. EventRegistration: object-with-__call listeners no longer leak a
 *     ReflectionException out of listen(); they are invoked context-less.
 * 18. Message/Job envelopes: non-string header values throw
 *     InvalidArgumentException (not TypeError from strlen). Serializer
 *     round-trip depth symmetric at 512 (deserialize was 64).
 *
 * === Security / Redis / telemetry / config env ===
 * 19. X-Replay-Id longer than the security bound is truncated instead of
 *     throwing uncaught (500 on attacker-controlled header length).
 * 20. ZEF_SECURITY_RATE_LIMIT_MAX/WINDOW/MAX_KEYS and CSRF_TOKEN_BYTES:
 *     non-numeric values fall back to the documented default instead of
 *     silently (int)-casting to 0→clamped 1 (a typo became 1 req/window).
 * 21. Redis DSN db index must be numeric: "redis://h/abc" previously
 *     selected db 0 silently. OtlpHttpJsonExporter::shutdown() idempotent.
 * 22. CounterMeter is monotonic: negative deltas rejected, overflow clamps
 *     at PHP_INT_MAX, NaN/INF rejected. TelemetrySanitizer scrubs invalid
 *     UTF-8 strings (mb_scrub) and maps non-finite floats to their string
 *     form — json_encode of OTLP payloads can no longer throw (previously
 *     JsonException escaped increment()/export and dropped whole batches).
 * 23. PipelineFactory builds the middleware stack in O(n) (was O(n²)).
 *
 * === Audit round 2 — verification findings (probes re-run against every fix) ===
 * 24. InProcessJobWorker::run() documents its daemon semantics (maxJobs is a
 *     CAP, not a target) and gains a $drain batch mode: run(drain: true)
 *     returns when the queue empties instead of idling forever.
 * 25. JobResult is truthful: $deadLettered is TRUE only when the job was
 *     really persisted to the DLQ (a full/absent DLQ no longer reports a
 *     dead-lettered job that actually vanished); retry-scheduled results now
 *     carry $willRetry = true so callers can distinguish them from losses.
 * 26. ErrorResponseFactory dev-mode diagnostics redact credential values
 *     ("password=hunter2" → "password=[REDACTED]") — raw exception messages
 *     previously leaked embedded secrets to clients in debug mode.
 * 27. Redis DSN db-index rejection message says WHY ("must be a non-negative
 *     integer") — signed forms like "/-1" were rejected with a misleading
 *     "numeric" complaint.
 * 28. Self-test suite "v2.7.0 edge-case regression" (36 assertions, one per
 *     fix above, public-API only) is registered in CliRunner; full suite is
 *     145 assertions. All 48 audit probes (2a–2g) green against this build.
 *
 * === Tests ===
 *  - New self-test suite "v2.7.0 edge-case regression" (36 assertions)
 *    covering every fix above through public APIs; full suite now 145.
 *
 * ----------------------------------------------------------------
 * CHANGELOG v2.6.0 (deep audit + refactor over v2.5.0-beta3)
 *
 * === Release blockers fixed (file was NOT parseable before) ===
 *  0a. Line 1: the shebang-style "//<?php" open tag left declare(strict_types)
 *      as a non-first statement — fatal on every include. Fixed to "<?php".
 *  0b. Header docblock contained a literal one-line docblock pattern whose
 *      closing sequence terminated the enclosing comment at line 34 - parse
 *      error. Reworded (and NOT reproduced here on purpose).
 *  0c. MessageBase used #[\Override] on PSR-7 methods without implementing
 *      MessageInterface — fatal at compile. MessageBase now implements it.
 *
 * === HTTP / PSR-7 bug fixes (all verified by runtime probes) ===
 *  1. RequestFactory::buildUri(): IPv6 hosts (Host: [::1]) were stripped of
 *     brackets and re-embedded bare, producing http://::1/ — Uri threw for
 *     every IPv6 request without a port. Fixed by re-bracketing.
 *  2. RequestFactory: requests without HTTP_HOST/SERVER_NAME (HTTP/1.0,
 *     CLI workers) crashed on "Malformed Host header". parseAuthority() now
 *     reports an empty authority and buildUri() falls back to 'localhost'
 *     (the previously unreachable fallback now actually works).
 *  3. Request/ServerRequest constructors bypassed withRequestTarget()'s CRLF
 *     validation, allowing request-splitting material into the request line.
 *     Both constructors now share RequestTrait::assertRequestTarget().
 *  4. parseAuthority()/Uri disagreement on FQDN trailing dots
 *     ("Host: example.com." crashed fromGlobals). One trailing root dot is
 *     now stripped and re-validated strictly.
 *  5. buildUri() used parse_url() on REQUEST_URI: "//foo/bar" (a legal
 *     origin-form target) was reinterpreted as a network path and silently
 *     corrupted to "/bar". New splitRequestTarget() parses origin-form
 *     targets manually; absolute URIs still delegate to parse_url().
 *  6. RequestTrait::withUri()/hostHeaderFromUri() emitted unbracketed IPv6
 *     Host headers (RFC 9110 §7.2 violation). Now mirrors getAuthority().
 *  7. ServerRequest constructor now validates the uploaded-files tree
 *     (previously only withUploadedFiles() did — asymmetric contract).
 *  8. RequestFactory::fromGlobals() split into fromGlobals() + injectable
 *     fromServer() so SAPI state can be simulated in tests.
 *  9. Stream: removed write-only dead property $size.
 *
 * === Container / config / messaging bug fixes ===
 * 10. ConfigAggregator::get()/all() read a never-populated $merged array and
 *     silently returned defaults until merge() was called manually. Both are
 *     now self-sufficient (idempotent merge() on first read).
 * 11. InProcessMessageBus::dispatch() used a shared mutable $index closure:
 *     middleware invoking $next twice (retry/fallback pattern) skipped the
 *     remaining chain and re-ran the terminal handler. Replaced with the
 *     immutable folded chain used by CqrsBusTrait/JobWorker. The handler is
 *     resolved once from the ORIGINAL envelope type (rewritten types can no
 *     longer null-deref the terminal step).
 * 12. InProcessJobWorker: a job whose type had no registered handler threw
 *     after the destructive dequeue — losing the job and aborting run().
 *     Unknown types are now routed to the dead-letter queue and reported as
 *     failed JobResults; the loop keeps processing.
 * 13. EventRegistration rejected string callables ('fn', 'Class::method')
 *     that pass is_callable(). Non-array/closure callables are normalized
 *     via Closure::fromCallable().
 * 14. EventDispatcher::dispatchWithContext() now aggregates ALL listener
 *     errors (matching EventDispatchException's list<Throwable> contract)
 *     instead of stopping after the first failure.
 * 15. ContainerResolver::scopeGet() raised an "Undefined array key" warning
 *     and returned null for unknown ids; it now throws LogicException.
 * 16. hasInContext(): dropped unused $scope parameter; callers updated.
 * 17. Dead code removal: CompiledContainerPlan never-read properties
 *     ($aliases/$lifetimes/$shared/$lazy), unreachable "?? $dependency"
 *     fallback in ContainerCompiler, unreachable $pos===false branch in
 *     DependencyGraphValidator::dfs().
 *
 * === Security / observability hardening ===
 * 18. AuthenticationMiddleware: operationClass was built from the UNTRUNCATED
 *     request path; any URL longer than ~124 bytes threw inside the security
 *     middleware (500 on attacker-controlled input). Now truncated to
 *     SecurityRequest::MAX_OPERATION_BYTES, like resourceFromRequest().
 * 19. Middleware ConfigProvider: ZEF_RATE_LIMIT_STORE=redis constructed a
 *     NEVER-CONNECTED \Redis — every request would 503 at runtime. Redis is
 *     now connected at boot from ZEF_REDIS_URL
 *     (redis://[user:pass@]host[:port][/db], ZEF_REDIS_TIMEOUT_MS); failure
 *     falls back to in-memory with a warning, as designed.
 * 20. StaticCredentialProvider compared bearer tokens with plain in_array()
 *     (timing side channel). Tokens are now compared as fixed-length SHA-256
 *     digests.
 * 21. SecurityRuntimeMiddleware: CSRF re-issues stale/invalid cookies on safe
 *     methods (previously browsers were permanently locked out after secret
 *     rotation), and the Secure attribute follows the policy instead of the
 *     request scheme (SameSite=None without Secure made browsers reject the
 *     cookie entirely).
 * 22. OriginPolicy::normalizeOrigin() rejected every IPv6 origin because
 *     parse_url keeps brackets. Brackets are stripped before validation and
 *     re-applied on output.
 * 23. Telemetry::shutdown(): final lifecycle counters were recorded after the
 *     last export (dead writes). Flag is set first, metrics are snapshotted
 *     and drained, then exporters are shut down.
 * 24. OtlpHttpJsonExporter: span event attributes are now emitted as OTLP
 *     KeyValue lists (previously a plain map — receivers dropped them), the
 *     event timestamp uses camelCase timeUnixNano, and counters export
 *     isMonotonic=true.
 * 25. Removed unused imports (Http: InvalidConfigurationException,
 *     Security: Response). JobContext now receives the envelope's headers as
 *     attributes (previously silently dropped).
 *
 * === Tests ===
 *  - New self-test suite "v2.6.0 regression" (20 assertions) covering every
 *    fix above through public APIs; full suite now 110 assertions.
 *
 * ----------------------------------------------------------------
 * CHANGELOG v2.5.0-beta3 (prior release: refactor + hardening over beta2)
 *
 * === Formatting / Clean Code (PSR-12) ===
 *  - Reformatted all minified one-liners to PSR-12 (4-space indent, one
 *    statement per line): Container section, Application constructor,
 *    PipelineFactory, MiddlewarePipeline, ModuleBootstrapper, Dispatcher,
 *    ResponseEmitter, Zef\Module\Core, Zef\Plugin\Toko, InProcessJobWorker,
 *    InMemoryJobIdempotencyStore, ErrorLogger, ConfigAggregator::get,
 *    Uri::with* methods, MessageBase::bodyString, Request, UploadedFile,
 *    Psr17Factory, RequestFactory, LimitedInputStream, EventDispatcher,
 *    CommandBus/QueryBus, BatchSpanProcessor, Telemetry, TelemetryLogger,
 *    OtlpHttpJsonExporter, TelemetrySanitizer, CliRunner, entrypoint block.
 *  - Fixed broken brace placement (}$this->queue[], }try{, } if (...) {).
 *  - Merged repeated namespace Zef\Framework\Container { use ...; } blocks.
 *  - Removed unused use imports; replaced FQCNs with use imports.
 *  - Removed stray blank-line runs; added consistent section banners.
 *  - Removed redundant readonly on properties inside final readonly class.
 *  - Converted SpanData to final readonly class.
 *  - Removed docblock noise (@return int) on typed methods.
 *  - Added #[\Override] consistently on interface implementations.
 *  - Removed dead CsrfTokenManager::issue() re-clamp (constructor validates).
 *  - Removed $sc = $c2 = new Container() double assignment in CliRunner.
 *
 * === Deduplication ===
 *  - Extracted trait RequestTrait (deriveRequestTarget, getRequestTarget,
 *    withRequestTarget, getMethod/withMethod, getUri/withUri, Host logic).
 *  - Extracted final class TrustedProxyMatcher (CIDR matching shared between
 *    RequestFactory and ClientAddressResolver).
 *  - Extracted final class Env (int/bool/string/csv helpers replacing inline
 *    getenv parsing in BatchSpanProcessor, RetryBackoffPolicy, Telemetry,
 *    SecurityPolicy, Middleware\ConfigProvider, RoadRunnerRuntime).
 *  - Merged Router::radixCandidates / radixMatchingCandidates into one method
 *    with $applyConstraints parameter.
 *  - Extracted CounterMeter::resolveSeries() (dedup overflow/eviction).
 *  - Made OtlpHttpJsonExporter::export() call postJson(); extracted endpointFor().
 *  - Extracted trait CqrsBusTrait (buildChain, resolveHandler, validateMessageClass).
 *  - Shared IdempotencyTrait between CQRS\InMemoryIdempotencyStore and
 *    Job\InMemoryJobIdempotencyStore (max key length parameterised).
 *  - Extracted final class Identifier with regex consts and assert* helpers.
 *  - Extracted shared assertBounded() into BoundedTrait used by
 *    CredentialHandle, SecurityContext, SecurityRequest.
 *  - Made CorsMiddleware::normalizeOrigin() delegate to OriginPolicy.
 *  - Added Uri private consts PATH_ALLOWED, QUERY_FRAGMENT_ALLOWED,
 *    USERINFO_ALLOWED; scheme regex const + assertScheme().
 *  - Extracted final class JsonResponse (static error() factory).
 *  - MessageBase::withHeader() = clone + setHeader() (removed duplicate body).
 *
 * === Bug Fixes / Hardening ===
 *  1. LimitedInputStream: seek/rewind now reset $observedBytes correctly.
 *  2. Uri::__construct: strips IPv6 brackets from parse_url host before
 *     assertHost() so IPv6 literal URLs no longer throw.
 *  3. HeaderValidator::assertValue() tightened to RFC 9110 (rejects \x01-\x08
 *     etc., accepts HTAB).
 *  4. InMemoryRateLimiter: added guards $maxKeys>=1, $key!='', $limit>=1,
 *     $windowSeconds>=1.
 *  5. ApcuRateLimiter::check(): uses apcu_add for counter init to avoid
 *     clobbering concurrent increments (documented, not self-testable).
 *  6. TelemetrySanitizer::string(): uses mb_strcut when available for UTF-8
 *     safe truncation.
 *  7. Stream::isWritable()/isReadable(): is_resource check moved before
 *     metadata('mode') call.
 *  8. RequestFactory::fromGlobals(): simplified superglobal access patterns.
 *  9. OtlpHttpJsonExporter: replaced @file_get_contents with scoped
 *     set_error_handler; transport error included in RuntimeException.
 * 10. Telemetry::fromEnvironment(): added $registerShutdownHook parameter.
 * 11. UploadedFile::moveTo(): replaced @fopen/@rename with scoped error
 *     handler; removed pointless function_exists('fflush').
 * 12. RoadRunnerWorkerAdapter: validates waitRequest/respond in constructor.
 * 13. ServiceLifetime: kept as final class; replaced bare 'singleton' literals
 *     in DependencyGraphValidator with ServiceLifetime::SINGLETON.
 * 14. ContainerResolver: extracted moduleFor() and throwNotFound() helpers.
 * 15. Router::add(): O(1) collision detection via signatureIndex map.
 * 16. SecurityRuntimeMiddleware::cookieValue(): trim($key) before comparing.
 * 17. SecurityPolicy/ConfigProvider: error_log routed through LoggerInterface
 *     where reachable; fallback error_log only when no logger.
 * 18. Application::__construct: LoggerInterface registered before TelemetryLogger.
 * 19. MessageBase: extracted assertProtocolVersion(); accepts '2' and '3'.
 *     RequestFactory::protocolVersion() updated (HTTP/2 -> '2').
 * 20. GlobalErrorHandler and Dispatcher both handle MethodNotAllowedException
 *     (defence in depth); both use JsonResponse.
 *
 * === Type Safety / Docblocks ===
 *  - Added generics-style PHPDoc on Container::getRegisteredIds(),
 *    Router::match() return shape, ServiceRegistryView, ConfigAggregator,
 *    MiddlewarePipeline::$stack, Application::$trustedHosts/$trustedProxies.
 *  - Added @param string|string[] $value on MessageBase::withHeader().
 *  - Marked @internal: ContainerCompiler, CompiledContainerPlan,
 *    ContainerResolver, ResolutionContext, ServiceRegistry, ServiceRegistrar.
 */

/* ===== SECTION 1 — PSR-11 COMPATIBILITY SHIM ===== */
