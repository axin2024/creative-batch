<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Campaigns
{
    public function __construct(private Db $db, private Catalog $catalog, private TranslationBackend $translator) {}

    public function create(Scope $scope, array $input): array
    {
        $scope->requireRole('designer');
        $name = Values::text($input['name'] ?? null, 160);
        return $this->db->transaction(function () use ($scope, $name): array {
            $this->catalog->lockStudio($scope);
            $id = Values::newId();
            $this->db->exec('INSERT INTO campaign_workspaces(tenant,id,name,owner) VALUES(?,?,?,?)',
                [$scope->tenant, $id, $name, $scope->actor]);
            $this->db->audit($scope, 'campaign-created', $id, ['name' => $name]);
            return $this->workspace($scope, $id);
        });
    }

    public function listing(Scope $scope): array
    {
        $scope->requireRole('designer', 'reviewer', 'operator');
        return $this->db->all('SELECT id,name,master_revision,owner,created_at FROM campaign_workspaces
            WHERE tenant=? ORDER BY created_at DESC,id DESC LIMIT 100', [$scope->tenant]);
    }

    public function workspace(Scope $scope, string $id): array
    {
        $scope->requireRole('designer', 'reviewer', 'operator');
        return $this->db->one('SELECT id,name,master_revision,owner,created_at FROM campaign_workspaces WHERE tenant=? AND id=?',
            [$scope->tenant, Values::token($id)]) ?? throw Problem::missing();
    }

    public function master(Scope $scope, string $id, ?int $revision = null): array
    {
        $campaign = $this->workspace($scope, $id);
        $row = $this->db->one('SELECT * FROM campaign_masters WHERE tenant=? AND campaign=? AND revision=?',
            [$scope->tenant, $id, $revision ?? $campaign['master_revision']]) ?? throw Problem::missing();
        return CampaignData::decodeRow($row, 'snapshot');
    }

    public function freeze(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('designer');
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $backgroundSource = isset($input['background']['source']) ? Values::token($input['background']['source'], 64) : null;
        $snapshot = [
            'source_locale' => CampaignData::locale($input['source_locale'] ?? null),
            'selling_points' => array_map(static fn($point): string => Values::text($point, 200), Values::listOf($input['selling_points'] ?? null, 1, 12)),
            'product' => $this->lockedLayer($scope, $input['product'] ?? null, true),
            'logo' => $this->lockedLayer($scope, $input['logo'] ?? null, false),
            'background' => ['color' => Values::hex($input['background']['color'] ?? null), 'source' => $backgroundSource],
            'allow_background' => Values::boolean($input['allow_background'] ?? null),
            'ink' => Values::hex($input['ink'] ?? null),
            'text_area' => CampaignData::rectangle($input['text_area'] ?? null),
            'segments' => [],
        ];
        if ($backgroundSource !== null) {
            $this->catalog->image($scope->tenant, $backgroundSource);
        }
        $seen = [];
        foreach (Values::listOf($input['segments'] ?? null, 2, 12) as $segment) {
            $key = Values::id($segment['id'] ?? null);
            if (isset($seen[$key])) {
                throw Problem::invalid('Source segment IDs must be unique.');
            }
            $kind = Values::choose($segment['kind'] ?? null, ['headline', 'body', 'disclaimer']);
            $snapshot['segments'][] = ['id' => $key, 'kind' => $kind, 'text' => Values::text($segment['text'] ?? null, 600)];
            $seen[$key] = $kind;
        }
        if (!in_array('headline', $seen, true) || !in_array('disclaimer', $seen, true)) {
            throw Problem::invalid('Freeze at least one headline and one disclaimer.');
        }
        return $this->db->transaction(function () use ($scope, $id, $expected, $snapshot): array {
            $this->catalog->lockStudio($scope);
            if ($this->workspace($scope, $id)['master_revision'] !== $expected) {
                throw Problem::conflict('The current master revision changed.');
            }
            $digest = CampaignData::digest($snapshot);
            $this->db->exec('INSERT INTO campaign_masters(tenant,campaign,revision,snapshot,digest,actor) VALUES(?,?,?,?,?,?)',
                [$scope->tenant, $id, $expected + 1, Values::json($snapshot), $digest, $scope->actor]);
            $this->db->exec('UPDATE campaign_workspaces SET master_revision=? WHERE tenant=? AND id=?', [$expected + 1, $scope->tenant, $id]);
            $this->db->audit($scope, 'master-frozen', $id, ['master_rev' => $expected + 1, 'master_sha256' => $digest,
                'immutable_layers' => ['product' => $snapshot['product']['source'], 'logo' => $snapshot['logo']['source']]]);
            return $this->master($scope, $id, $expected + 1);
        });
    }

    private function lockedLayer(Scope $scope, mixed $input, bool $canAdopt): array
    {
        $selected = null;
        if ($canAdopt && isset($input['legacy_candidate'])) {
            $selected = Values::token($input['legacy_candidate']);
            $row = $this->db->one('SELECT c.image_digest FROM candidates c JOIN selections s
                ON (s.tenant,s.batch,s.unit_key,s.candidate)=(c.tenant,c.batch,c.unit_key,c.id)
                WHERE c.tenant=? AND c.id=? AND c.verdict=\'approved\' AND s.revision=(SELECT MAX(latest.revision) FROM selections latest
                WHERE (latest.tenant,latest.batch,latest.unit_key)=(s.tenant,s.batch,s.unit_key))',
                [$scope->tenant, $selected]) ?? throw Problem::missing();
            $digest = $row['image_digest'];
        } else {
            $digest = Values::token($input['source'] ?? null, 64);
        }
        $this->catalog->image($scope->tenant, $digest);
        return ['source' => $digest, 'rect' => CampaignData::rectangle($input['rect'] ?? null), 'legacy_candidate' => $selected];
    }

    public function terms(Scope $scope, string $id, string $locale, ?int $revision = null): array
    {
        $this->workspace($scope, $id);
        $locale = CampaignData::locale($locale);
        $row = $revision === null
            ? $this->db->one('SELECT * FROM campaign_terms WHERE tenant=? AND campaign=? AND locale=? ORDER BY revision DESC LIMIT 1',
                [$scope->tenant, $id, $locale])
            : $this->db->one('SELECT * FROM campaign_terms WHERE tenant=? AND campaign=? AND locale=? AND revision=?',
                [$scope->tenant, $id, $locale, $revision]);
        return CampaignData::decodeRow($row ?? throw Problem::missing(), 'snapshot');
    }

    public function setTerms(Scope $scope, string $id, array $input): array
    {
        $scope->requireRole('reviewer');
        $locale = CampaignData::locale($input['locale'] ?? null);
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $snapshot = ['locale' => $locale, 'required' => [], 'banned' => [], 'disclaimers' => []];
        foreach (Values::listOf($input['required'] ?? [], 0, 40) as $term) {
            $snapshot['required'][] = ['source' => Values::text($term['source'] ?? null, 120), 'target' => Values::text($term['target'] ?? null, 120)];
        }
        foreach (Values::listOf($input['banned'] ?? [], 0, 40) as $word) {
            $snapshot['banned'][] = Values::text($word, 120);
        }
        foreach (Values::listOf($input['disclaimers'] ?? null, 1, 12) as $disclaimer) {
            $key = Values::id($disclaimer['segment'] ?? null);
            if (isset($snapshot['disclaimers'][$key])) {
                throw Problem::invalid('Each disclaimer segment must have one approved legal wording.');
            }
            $snapshot['disclaimers'][$key] = Values::text($disclaimer['text'] ?? null, 600);
        }
        return $this->db->transaction(function () use ($scope, $id, $expected, $locale, $snapshot): array {
            $this->catalog->lockStudio($scope);
            $master = $this->master($scope, $id)['snapshot'];
            $legal = array_column(array_filter($master['segments'], static fn(array $segment): bool => $segment['kind'] === 'disclaimer'), 'id');
            if (array_diff($legal, array_keys($snapshot['disclaimers'])) || array_diff(array_keys($snapshot['disclaimers']), $legal)) {
                throw Problem::invalid('Provide approved wording for each disclaimer in the current master.');
            }
            $row = $this->db->one('SELECT MAX(revision) AS revision FROM campaign_terms WHERE tenant=? AND campaign=? AND locale=?',
                [$scope->tenant, $id, $locale]);
            if ((int) $row['revision'] !== $expected) {
                throw Problem::conflict('The approved terms revision changed.');
            }
            $this->db->exec('INSERT INTO campaign_terms(tenant,campaign,locale,revision,snapshot,actor) VALUES(?,?,?,?,?,?)',
                [$scope->tenant, $id, $locale, $expected + 1, Values::json($snapshot), $scope->actor]);
            $this->db->audit($scope, 'campaign-terms-approved', $id, ['locale' => $locale, 'term_rev' => $expected + 1]);
            return $this->terms($scope, $id, $locale, $expected + 1);
        });
    }

    public function registerFont(Scope $scope, string $bytes, mixed $family, mixed $license, mixed $evidence): array
    {
        $scope->requireRole('designer', 'reviewer');
        if (strlen($bytes) < 64 || strlen($bytes) > 4 * 1024 * 1024 || !in_array(substr($bytes, 0, 4), ["\0\1\0\0", 'OTTO'], true)) {
            throw Problem::invalid('Upload a TrueType or OpenType font up to 4 MiB.');
        }
        $digest = hash('sha256', $bytes);
        $family = Values::text($family, 160);
        $license = Values::text($license, 120);
        $evidence = Values::text($evidence, 600);
        return $this->db->transaction(function () use ($scope, $bytes, $digest, $family, $license, $evidence): array {
            $this->catalog->lockStudio($scope);
            $this->db->exec('INSERT INTO campaign_fonts(tenant,digest,family,license,evidence,bytes,actor) VALUES(?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE digest=campaign_fonts.digest', [$scope->tenant, $digest, $family, $license, $evidence, $bytes, $scope->actor]);
            $stored = $this->font($scope, $digest, false);
            if ($stored['family'] !== $family || $stored['license'] !== $license || $stored['evidence'] !== $evidence) {
                throw Problem::conflict('The same font has already been registered with different rights evidence.');
            }
            $this->db->audit($scope, 'campaign-font-registered', $digest, ['family' => $family, 'license' => $license]);
            return $stored;
        });
    }

    public function font(Scope $scope, string $digest, bool $includeBytes): array
    {
        $scope->requireRole('designer', 'reviewer', 'operator');
        $columns = $includeBytes ? 'digest,family,license,evidence,bytes' : 'digest,family,license,evidence';
        return $this->db->one("SELECT $columns FROM campaign_fonts WHERE tenant=? AND digest=?", [$scope->tenant, Values::token($digest, 64)])
            ?? throw Problem::missing();
    }

    public function localeVersion(Scope $scope, string $id, string $territory, string $locale, ?int $revision = null): array
    {
        $this->workspace($scope, $id);
        $keys = [$scope->tenant, $id, CampaignData::territory($territory), CampaignData::locale($locale)];
        $sql = 'SELECT * FROM campaign_localizations WHERE tenant=? AND campaign=? AND territory=? AND locale=?';
        $row = $revision === null ? $this->db->one($sql . ' ORDER BY revision DESC LIMIT 1', $keys)
            : $this->db->one($sql . ' AND revision=?', [...$keys, $revision]);
        return CampaignData::decodeRow($row ?? throw Problem::missing(), 'snapshot', 'provenance');
    }

    public function localize(Scope $scope, string $id, array $input, bool $translate): array
    {
        $scope->requireRole('designer', 'reviewer');
        $territory = CampaignData::territory($input['territory'] ?? null);
        $locale = CampaignData::locale($input['locale'] ?? null);
        $expected = Values::integer($input['expected_revision'] ?? null, 0, 1_000_000);
        $master = $this->master($scope, $id, Values::integer($input['master_rev'] ?? null, 1, 1_000_000));
        $terms = $this->terms($scope, $id, $locale, Values::integer($input['term_rev'] ?? null, 1, 1_000_000));
        $font = Values::token($input['font'] ?? null, 64);
        $this->font($scope, $font, false);
        $background = $master['snapshot']['background'];
        if (isset($input['product']) || isset($input['logo']) || isset($input['immutable_layers'])) {
            throw CampaignData::releaseField('immutable_layers', 'The product and logo layers come from the frozen master.');
        }
        if (isset($input['background'])) {
            if (!$master['snapshot']['allow_background']) {
                throw CampaignData::releaseField('background', 'The background is locked by this master.');
            }
            $digest = isset($input['background']['source']) ? Values::token($input['background']['source'], 64) : null;
            $background = ['color' => Values::hex($input['background']['color'] ?? null), 'source' => $digest];
            if ($digest !== null) {
                $this->catalog->image($scope->tenant, $digest);
            }
        }
        $record = $translate ? $this->translator->suggest($master['snapshot'], $terms['snapshot'])
            : ['copy' => $input['copy'] ?? null, 'provenance' => ['source' => 'manual', 'reason' => Values::text($input['reason'] ?? null, 600), 'cost_source' => 'manual']];
        $target = $this->copy($master['snapshot'], $terms['snapshot'], $record['copy']);
        $snapshot = ['background' => $background, 'font' => $font, 'copy' => $target['copy'], 'language_flags' => $target['flags'],
            'direction' => in_array(explode('-', $locale)[0], ['ar', 'fa', 'he', 'ps', 'ur', 'yi', 'dv', 'sd'], true) ? 'rtl' : 'ltr'];
        return $this->db->transaction(function () use ($scope, $id, $expected, $territory, $locale, $master, $terms, $snapshot, $record): array {
            $this->catalog->lockStudio($scope);
            if ($this->workspace($scope, $id)['master_revision'] !== $master['revision'] ||
                $this->terms($scope, $id, $locale)['revision'] !== $terms['revision']) {
                throw Problem::conflict('The active master or approved terms changed while preparing the draft.');
            }
            $previous = $this->db->one('SELECT revision,snapshot,provenance FROM campaign_localizations
                WHERE tenant=? AND campaign=? AND territory=? AND locale=? ORDER BY revision DESC LIMIT 1',
                [$scope->tenant, $id, $territory, $locale]);
            if (($previous['revision'] ?? 0) !== $expected) {
                throw Problem::conflict('The draft revision changed.');
            }
            $provenance = $record['provenance'];
            if ($previous !== null && $provenance['source'] === 'manual') {
                $base = Values::decode($previous['snapshot'])['copy'];
                $baseProvenance = Values::decode($previous['provenance']);
                $provenance['parent_revision'] = $expected;
                $provenance['edited_segments'] = array_values(array_keys(array_diff_assoc($snapshot['copy'], $base)));
                $provenance['changed_bytes'] = array_sum(array_map(static fn(string $key): int =>
                    levenshtein($base[$key] ?? '', $snapshot['copy'][$key]), $provenance['edited_segments']));
                $provenance['suggestion_revision'] = $baseProvenance['source'] === 'local-model' ? $expected : ($baseProvenance['suggestion_revision'] ?? null);
            }
            $this->db->exec('INSERT INTO campaign_localizations(tenant,campaign,territory,locale,revision,master_revision,term_revision,snapshot,provenance,actor)
                VALUES(?,?,?,?,?,?,?,?,?,?)', [$scope->tenant, $id, $territory, $locale, $expected + 1, $master['revision'],
                $terms['revision'], Values::json($snapshot), Values::json($provenance), $scope->actor]);
            $this->db->audit($scope, 'localization-saved', $id, ['territory' => $territory, 'locale' => $locale,
                'segment_rev' => $expected + 1, 'flags' => $snapshot['language_flags']]);
            return $this->localeVersion($scope, $id, $territory, $locale, $expected + 1);
        });
    }

    private function copy(array $master, array $terms, mixed $raw): array
    {
        if (!is_array($raw) || array_diff(array_keys($raw), array_column($master['segments'], 'id'))) {
            throw CampaignData::releaseField('copy', 'Use the segment IDs from the frozen master.');
        }
        $copy = [];
        $flags = [];
        foreach ($master['segments'] as $segment) {
            $id = $segment['id'];
            $text = Values::text($raw[$id] ?? '(missing)', 2000);
            $copy[$id] = array_key_exists($id, $raw) ? $text : '';
            if ($copy[$id] === '') {
                $flags[] = ['segment' => $id, 'code' => 'missing-segment'];
            }
            foreach ($terms['banned'] as $term) {
                if (self::contains($text, $term)) {
                    $flags[] = ['segment' => $id, 'code' => 'banned-term', 'term' => $term];
                }
            }
            foreach ($terms['required'] as $term) {
                if (self::contains($segment['text'], $term['source']) && !self::contains($text, $term['target'])) {
                    $flags[] = ['segment' => $id, 'code' => 'required-term-missing', 'term' => $term['target']];
                }
            }
            if ($segment['kind'] === 'disclaimer' && $text !== ($terms['disclaimers'][$id] ?? null)) {
                $flags[] = ['segment' => $id, 'code' => 'disclaimer-mismatch'];
            }
        }
        return ['copy' => $copy, 'flags' => $flags];
    }

    private static function contains(string $text, string $term): bool
    {
        return preg_match('/(?<![\p{L}\p{N}\p{M}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}\p{M}])/iu', $text) === 1;
    }
}
