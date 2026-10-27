<?php

namespace NiceTry\Transfer;

use App\Enums\GymExit;
use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Enums\HostHeaderMode;
use App\Enums\HstsPolicy;
use App\Enums\ProtectionMode;
use App\Rules\HeaderNames;
use App\Rules\Hostname;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

/**
 * The transfer bundle: one origin and its team's rules and routines, as ExportOrigin writes it and
 * ImportOrigin reads it. Nothing in one is trusted: decode() takes it apart only as JSON within the
 * size and depth below, and validate() accepts exactly the fields of this format's version, each
 * typed and bounded as the panel's own forms bound them. An unknown field anywhere refuses the whole.
 */
final class TransferBundle
{
    public const string FORMAT = 'nicetrybot-transfer';

    public const int VERSION = 1;

    /** How a routine's verdict condition names a rule, in place of its id. */
    public const string RULE_PREFIX = 'rule:';

    /** The largest bundle read, as a file or from another panel. */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const int MAX_SITES = 20_000;

    public const int MAX_ZONES = 1_000;

    public const int MAX_RULES = 500;

    public const int MAX_ROUTINES = 200;

    /**
     * Read a bundle from JSON and validate it.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTransferBundle
     */
    public static function decode(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidTransferBundle(__('That transfer is larger than :size MB.', ['size' => self::MAX_BYTES / 1024 / 1024]));
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidTransferBundle(__('That isn\'t a NiceTryBot transfer file: it isn\'t JSON.'));
        }

        if (! is_array($data)) {
            throw new InvalidTransferBundle(__('That isn\'t a NiceTryBot transfer file.'));
        }

        return self::validate($data);
    }

    /**
     * Accept a bundle only when it's exactly this format: the right version, every field known, typed
     * and within bounds, every hostname a hostname, and every obstacle's settings plain values.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     *
     * @throws InvalidTransferBundle
     */
    public static function validate(array $data): array
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidTransferBundle(__('That isn\'t a NiceTryBot transfer file.'));
        }

        if (($data['version'] ?? null) !== self::VERSION) {
            throw new InvalidTransferBundle(__('That transfer is version :version, and this panel reads version :ours. Update NiceTryBot here, or export it again from a panel that matches.', [
                'version' => is_scalar($data['version'] ?? null) ? (string) $data['version'] : '?',
                'ours' => self::VERSION,
            ]));
        }

        $unknown = array_diff(array_map(strval(...), array_keys($data)), ['format', 'version', 'exported_at', 'source', 'origin', 'rules', 'routines']);

        if ($unknown !== []) {
            throw new InvalidTransferBundle(__('That transfer file isn\'t valid: it has fields this panel doesn\'t know (:fields).', ['fields' => implode(', ', array_slice($unknown, 0, 5))]));
        }

        $validator = Validator::make($data, self::rules());

        if ($validator->fails()) {
            throw new InvalidTransferBundle(__('That transfer file isn\'t valid: :problem', ['problem' => $validator->errors()->first()]));
        }

        foreach ($data['routines'] as $routine) {
            foreach ($routine['obstacles'] as $obstacle) {
                foreach ($obstacle['config'] as $key => $value) {
                    if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) || ! (is_scalar($value) || $value === null) || (is_string($value) && strlen($value) > 1000)) {
                        throw new InvalidTransferBundle(__('That transfer file isn\'t valid: an obstacle in “:routine” has a setting that isn\'t a plain value.', ['routine' => $routine['name']]));
                    }
                }
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * The validation rules: array:<keys> refuses any field not listed.
     *
     * @return array<string, mixed>
     */
    private static function rules(): array
    {
        $obstacleKey = ['string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'];

        return [
            'format' => ['required', 'string'],
            'version' => ['required', 'integer'],
            'exported_at' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:253'],

            'origin' => ['required', 'array:name,origin_url,host_header_mode,origin_host_header,forwarded_host_header,origin_timeout_seconds,server_header,hsts_policy,cache_assets,fold_www,protection_mode,origin_secret,authorize_url,zones,sites'],
            'origin.name' => ['required', 'string', 'max:100'],
            'origin.origin_url' => ['required', 'string', 'max:255', 'url:http,https'],
            'origin.host_header_mode' => ['required', Rule::enum(HostHeaderMode::class)],
            'origin.origin_host_header' => ['nullable', 'string', new Hostname],
            'origin.forwarded_host_header' => ['nullable', 'string', 'max:255', new HeaderNames],
            'origin.origin_timeout_seconds' => ['required', 'integer', 'between:5,120'],
            'origin.server_header' => ['nullable', 'string', 'max:100', 'regex:/^[\x20-\x7E]+$/'],
            'origin.hsts_policy' => ['required', Rule::enum(HstsPolicy::class)],
            'origin.cache_assets' => ['required', 'boolean'],
            'origin.fold_www' => ['required', 'boolean'],
            'origin.protection_mode' => ['required', Rule::enum(ProtectionMode::class)],
            'origin.origin_secret' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'origin.authorize_url' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'origin.zones' => ['present', 'array', 'list', 'max:'.self::MAX_ZONES],
            'origin.zones.*' => ['array:suffix,serves_www'],
            'origin.zones.*.suffix' => ['required', 'string', new Hostname],
            'origin.zones.*.serves_www' => ['required', 'boolean'],
            'origin.sites' => ['present', 'array', 'list', 'max:'.self::MAX_SITES],
            'origin.sites.*' => ['array:hostname,protection_mode'],
            'origin.sites.*.hostname' => ['required', 'string', new Hostname],
            'origin.sites.*.protection_mode' => ['nullable', Rule::enum(ProtectionMode::class)],

            'rules' => ['present', 'array', 'list', 'max:'.self::MAX_RULES],
            'rules.*' => ['array:name,source,enabled'],
            'rules.*.name' => ['required', 'string', 'max:100'],
            'rules.*.source' => ['required', 'string', 'max:20000'],
            'rules.*.enabled' => ['required', 'boolean'],

            'routines' => ['present', 'array', 'list', 'max:'.self::MAX_ROUTINES],
            'routines.*' => ['array:name,enabled,match_all,conditions,obstacles,exit_action,pass_seconds,one_try'],
            'routines.*.name' => ['required', 'string', 'max:100'],
            'routines.*.enabled' => ['required', 'boolean'],
            'routines.*.match_all' => ['required', 'boolean'],
            'routines.*.conditions' => ['present', 'array', 'list', 'max:50'],
            'routines.*.conditions.*' => ['array:field,operator,values'],
            'routines.*.conditions.*.field' => ['required', Rule::enum(GymField::class)],
            'routines.*.conditions.*.operator' => ['required', Rule::enum(GymOperator::class)],
            'routines.*.conditions.*.values' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'routines.*.conditions.*.values.*' => ['string', 'max:255'],
            'routines.*.obstacles' => ['present', 'array', 'list', 'max:20'],
            'routines.*.obstacles.*' => ['array:id,type,config,escalate'],
            'routines.*.obstacles.*.id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'routines.*.obstacles.*.type' => ['required', ...$obstacleKey],
            'routines.*.obstacles.*.config' => ['present', 'array', 'max:50'],
            'routines.*.obstacles.*.escalate' => ['nullable', ...$obstacleKey],
            'routines.*.exit_action' => ['required', Rule::enum(GymExit::class)],
            'routines.*.pass_seconds' => ['required', 'integer', 'between:60,2592000'],
            'routines.*.one_try' => ['required', 'boolean'],
        ];
    }
}
