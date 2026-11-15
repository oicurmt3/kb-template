<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

admin_require();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $webCfg = $config['web'] ?? [];
        $ragCfg = $config['rag'] ?? [];
        $raw = AppSettings::get(AppSettings::KEY_WEB_ALLOWED_HOSTS);
        $fromDb = $raw !== null && $raw !== '';
        $hosts = AppSettings::allowedHostsFromConfig($webCfg);
        $ageRaw = AppSettings::get(AppSettings::KEY_SIMPLE_EXPLAIN_AGE);
        $lenRaw = AppSettings::get(AppSettings::KEY_ANSWER_LENGTH);
        $uiRaw = AppSettings::get(AppSettings::KEY_UI);
        json_out([
            'allowed_hosts' => $hosts,
            'hosts_text' => AppSettings::hostsToText($hosts),
            'source' => $fromDb ? 'admin' : 'config',
            'enabled' => !empty($webCfg['enabled']),
            'simple_explain_age' => AppSettings::simpleExplainAge(),
            'simple_explain_age_source' => ($ageRaw !== null && $ageRaw !== '') ? 'admin' : 'default',
            'answer_length' => AppSettings::answerLength(),
            'answer_length_source' => ($lenRaw !== null && $lenRaw !== '') ? 'admin' : 'default',
            'adaptive_retrieval' => AppSettings::adaptiveRetrievalEnabled($ragCfg),
            'adaptive_retrieval_source' => AppSettings::adaptiveRetrievalSource($ragCfg),
            'rag_web_mix' => AppSettings::ragWebMix(),
            'rag_web_mix_modes' => AppSettings::ragWebMixModes(),
            'ui' => AppSettings::uiConfig(),
            'ui_source' => ($uiRaw !== null && $uiRaw !== '') ? 'admin' : 'default',
            'ui_feature_keys' => AppSettings::uiFeatureKeys(),
            'ui_toggle_keys' => AppSettings::uiToggleFeatureKeys(),
            'ui_visibility_only_keys' => AppSettings::uiVisibilityOnlyKeys(),
        ]);
    }

    if ($method === 'POST') {
        csrf_check();
        if (!rate_limit_check('admin_settings', 30, 60)) {
            json_error('Rate limit exceeded', 429);
        }
        $body = read_json_body();
        $ragCfg = $config['rag'] ?? [];
        $updatedHosts = false;
        $updatedAge = false;
        $updatedLength = false;
        $updatedAdaptive = false;
        $hosts = AppSettings::allowedHostsFromConfig($config['web'] ?? []);
        $age = AppSettings::simpleExplainAge();
        $answerLength = AppSettings::answerLength();
        $adaptive = AppSettings::adaptiveRetrievalEnabled($ragCfg);

        $hasHosts = array_key_exists('hosts_text', $body) || array_key_exists('allowed_hosts', $body);
        $hasAge = array_key_exists('simple_explain_age', $body);
        $hasLength = array_key_exists('answer_length', $body);
        $hasAdaptive = array_key_exists('adaptive_retrieval', $body);
        $hasRagWebMix = array_key_exists('rag_web_mix', $body);
        $hasUi = array_key_exists('ui', $body);
        $ui = AppSettings::uiConfig();
        $updatedUi = false;
        $ragWebMix = AppSettings::ragWebMix();
        $updatedRagWebMix = false;

        if (!$hasHosts && !$hasAge && !$hasLength && !$hasAdaptive && !$hasRagWebMix && !$hasUi) {
            json_error('Provide hosts, simple_explain_age, answer_length, adaptive_retrieval, rag_web_mix, and/or ui');
        }

        if ($hasHosts) {
            if (isset($body['hosts_text']) && is_string($body['hosts_text'])) {
                $hosts = AppSettings::normalizeHosts($body['hosts_text']);
            } else {
                $hosts = AppSettings::normalizeHosts($body['allowed_hosts']);
            }
            if ($hosts === []) {
                json_error('Add at least one valid domain (e.g. workcover.wa.gov.au)');
            }
            AppSettings::set(
                AppSettings::KEY_WEB_ALLOWED_HOSTS,
                json_encode($hosts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $updatedHosts = true;
        }

        if ($hasAge) {
            if (!is_numeric($body['simple_explain_age'])) {
                json_error('simple_explain_age must be a number (5–18)');
            }
            $age = AppSettings::saveSimpleExplainAge((int)$body['simple_explain_age']);
            $updatedAge = true;
        }

        if ($hasLength) {
            $answerLength = AppSettings::saveAnswerLength((string)$body['answer_length']);
            $updatedLength = true;
        }

        if ($hasAdaptive) {
            $raw = $body['adaptive_retrieval'];
            $on = false;
            if (is_bool($raw)) {
                $on = $raw;
            } elseif (is_numeric($raw)) {
                $on = ((int)$raw) === 1;
            } else {
                $on = in_array(strtolower(trim((string)$raw)), ['1', 'true', 'yes', 'on'], true);
            }
            $adaptive = AppSettings::saveAdaptiveRetrieval($on);
            $updatedAdaptive = true;
        }

        if ($hasRagWebMix) {
            if (!is_array($body['rag_web_mix'])) {
                json_error('rag_web_mix must be an object with mode and degree');
            }
            $ragWebMix = AppSettings::saveRagWebMix($body['rag_web_mix']);
            $updatedRagWebMix = true;
        }

        if ($hasUi) {
            if (!is_array($body['ui'])) {
                json_error('ui must be an object');
            }
            $ui = AppSettings::saveUiConfig($body['ui']);
            $updatedUi = true;
        }

        $uiRaw = AppSettings::get(AppSettings::KEY_UI);
        json_out([
            'ok' => true,
            'allowed_hosts' => $hosts,
            'hosts_text' => AppSettings::hostsToText($hosts),
            'source' => $updatedHosts ? 'admin' : (
                (AppSettings::get(AppSettings::KEY_WEB_ALLOWED_HOSTS) ? 'admin' : 'config')
            ),
            'simple_explain_age' => $age,
            'simple_explain_age_source' => (
                AppSettings::get(AppSettings::KEY_SIMPLE_EXPLAIN_AGE) !== null
                && AppSettings::get(AppSettings::KEY_SIMPLE_EXPLAIN_AGE) !== ''
            ) ? 'admin' : 'default',
            'answer_length' => $answerLength,
            'answer_length_source' => (
                AppSettings::get(AppSettings::KEY_ANSWER_LENGTH) !== null
                && AppSettings::get(AppSettings::KEY_ANSWER_LENGTH) !== ''
            ) ? 'admin' : 'default',
            'adaptive_retrieval' => $adaptive,
            'adaptive_retrieval_source' => AppSettings::adaptiveRetrievalSource($ragCfg),
            'rag_web_mix' => $ragWebMix,
            'rag_web_mix_modes' => AppSettings::ragWebMixModes(),
            'ui' => $ui,
            'ui_source' => ($uiRaw !== null && $uiRaw !== '') ? 'admin' : 'default',
            'updated_hosts' => $updatedHosts,
            'updated_age' => $updatedAge,
            'updated_length' => $updatedLength,
            'updated_adaptive' => $updatedAdaptive,
            'updated_rag_web_mix' => $updatedRagWebMix,
            'updated_ui' => $updatedUi,
        ]);
    }

    json_error('Method not allowed', 405);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if (stripos($msg, 'settings') !== false && stripos($msg, "doesn't exist") !== false) {
        json_error('Settings table missing — run sql/schema.sql (CREATE TABLE settings)', 500);
    }
    json_error($msg, 500);
}
