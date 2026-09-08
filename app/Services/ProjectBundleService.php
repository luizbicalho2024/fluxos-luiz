<?php

namespace App\Services;

use App\Models\Flowchart;
use App\Models\Project;
use Illuminate\Support\Str;
use ZipArchive;

class ProjectBundleService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly FlowRepository $flows,
        private readonly FlowDocumentService $documents,
    ) {}

    public function export(Project $project, string $username, bool $isAdmin = false): string
    {
        $flows = $this->projects->projectFlows($project, $username, $isAdmin);
        $manifest = [
            'format' => 'fluxos-luiz-project',
            'version' => '1.1',
            'project' => [
                'id' => (string) $project->_id,
                'name' => (string) $project->name,
                'code' => (string) $project->code,
                'description' => (string) $project->description,
                'status' => (string) $project->status,
                'visibility' => (string) $project->visibility,
                'tags' => (array) ($project->tags ?? []),
                'settings' => (array) ($project->settings ?? []),
                'default_flow_id' => (string) ($project->default_flow_id ?? ''),
                // Mantém também o alias legado para facilitar ida e volta com Produto Tools <= 3.x.
                'defaultFlowId' => (string) ($project->default_flow_id ?? ''),
                'current_release' => (int) ($project->current_release ?? 0),
            ],
            'flows' => $flows->map(fn ($flow) => [
                'id' => (string) $flow->_id,
                'flowId' => (string) $flow->_id,
                'name' => (string) $flow->name,
                'role' => (string) ($flow->project_role ?? ''),
                'group' => (string) ($flow->project_group ?? ''),
                'order' => (int) ($flow->project_order ?? 0),
                'version' => (int) ($flow->current_version ?? 1),
                'revision' => (int) ($flow->revision ?? 1),
                'hash' => (string) ($flow->document_hash ?? ''),
                'file' => 'flows/'.(string) $flow->_id.'.json',
            ])->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'project_zip_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível criar o pacote do projeto.');
        }

        $zip->addFromString('project.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $zip->addFromString('README.txt', "Fluxos Luiz - pacote de projeto\nProjeto: {$project->name}\nGerado em: ".now()->format('d/m/Y H:i')."\n");
        foreach ($flows as $flow) {
            $zip->addFromString(
                'flows/'.(string) $flow->_id.'.json',
                json_encode($flow->document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            );
        }
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes ?: '';
    }

    /**
     * Lê um project.zip sem persistir nada.
     *
     * Compatível com:
     * - Fluxos Luiz 4.x: flows[].id + flows/{id}.json
     * - Produto Tools 3.x: flows[].flowId + flows[].file
     * - project.json em subdiretório quando o usuário compactou a pasta inteira
     * - BOM UTF-8
     * - fallback por varredura de flows/*.json quando o manifesto estiver incompleto
     */
    public function parseZip(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'project_import_');
        if ($tmp === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo temporário de importação.');
        }
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive();
        try {
            if ($zip->open($tmp) !== true) {
                throw new \InvalidArgumentException('O arquivo não é um project.zip válido.');
            }

            $entries = $this->zipEntries($zip);
            $projectEntry = $this->findProjectManifestEntry($entries);
            if ($projectEntry === null) {
                throw new \InvalidArgumentException('O pacote não contém project.json.');
            }

            $manifestRaw = $zip->getFromName($entries[$projectEntry]);
            if ($manifestRaw === false) {
                throw new \InvalidArgumentException('Não foi possível ler project.json.');
            }
            $manifest = $this->decodeJson($manifestRaw, 'project.json');

            $projectMeta = $this->normalizeProjectMeta((array) ($manifest['project'] ?? []));
            $flowDefs = is_array($manifest['flows'] ?? null) ? $manifest['flows'] : [];
            $documents = [];
            $loadedEntries = [];

            $manifestDir = dirname($projectEntry);
            $manifestPrefix = $manifestDir === '.' ? '' : trim($manifestDir, '/').'/';

            foreach ($flowDefs as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $oldId = trim((string) ($definition['id'] ?? $definition['flowId'] ?? $definition['flow_id'] ?? ''));

                if (is_array($definition['document'] ?? null)) {
                    $document = $definition['document'];
                    $oldId = $oldId !== '' ? $oldId : (string) ($document['flow']['id'] ?? '');
                    if ($this->isFlowDocument($document)) {
                        $this->applyManifestFlowMetadata($document, $definition, $oldId);
                        $documents[$oldId !== '' ? $oldId : 'flow_'.count($documents)] = $document;
                    }
                    continue;
                }

                $declaredFile = trim((string) ($definition['file'] ?? ''));
                $candidates = [];
                if ($declaredFile !== '') {
                    $declaredFile = $this->normalizeZipPath($declaredFile);
                    $candidates[] = $declaredFile;
                    if ($manifestPrefix !== '' && !str_starts_with($declaredFile, $manifestPrefix)) {
                        $candidates[] = $manifestPrefix.$declaredFile;
                    }
                }
                if ($oldId !== '') {
                    $candidates[] = 'flows/'.$oldId.'.json';
                    if ($manifestPrefix !== '') {
                        $candidates[] = $manifestPrefix.'flows/'.$oldId.'.json';
                    }
                }

                $entry = $this->firstExistingEntry($entries, $candidates);
                if ($entry === null) {
                    continue;
                }

                $raw = $zip->getFromName($entries[$entry]);
                if ($raw === false) {
                    continue;
                }

                $document = $this->decodeJson($raw, $entry);
                if (!$this->isFlowDocument($document)) {
                    continue;
                }

                $oldId = $oldId !== '' ? $oldId : trim((string) ($document['flow']['id'] ?? pathinfo($entry, PATHINFO_FILENAME)));
                $this->applyManifestFlowMetadata($document, $definition, $oldId);
                $documents[$oldId] = $document;
                $loadedEntries[strtolower($entry)] = true;
            }

            // Produto Tools já fazia fallback para flows/*.json. Mantemos o mesmo comportamento
            // e ainda aceitamos um project.json dentro de uma pasta raiz do ZIP.
            foreach ($entries as $normalized => $original) {
                $lower = strtolower($normalized);
                if ($lower === strtolower($projectEntry) || isset($loadedEntries[$lower]) || !str_ends_with($lower, '.json')) {
                    continue;
                }

                $relative = $manifestPrefix !== '' && str_starts_with($normalized, $manifestPrefix)
                    ? substr($normalized, strlen($manifestPrefix))
                    : $normalized;
                $relativeLower = strtolower($relative);
                if (!str_starts_with($relativeLower, 'flows/')) {
                    continue;
                }

                $raw = $zip->getFromName($original);
                if ($raw === false) {
                    continue;
                }

                try {
                    $document = $this->decodeJson($raw, $normalized);
                } catch (\InvalidArgumentException) {
                    // Um JSON auxiliar quebrado não deve invalidar os demais fluxos encontrados.
                    continue;
                }
                if (!$this->isFlowDocument($document)) {
                    continue;
                }

                $oldId = trim((string) ($document['flow']['id'] ?? pathinfo($normalized, PATHINFO_FILENAME)));
                if ($oldId === '') {
                    continue;
                }

                $definition = $this->findFlowDefinition($flowDefs, $oldId, $relative);
                $this->applyManifestFlowMetadata($document, $definition, $oldId);
                $documents[$oldId] ??= $document;
            }

            if (!$documents) {
                throw new \InvalidArgumentException('O pacote não contém fluxos JSON válidos. Verifique se o ZIP possui project.json e a pasta flows com arquivos .json.');
            }

            return [
                'manifest' => $manifest,
                'project' => $projectMeta,
                'documents' => $documents,
                'project_entry' => $projectEntry,
            ];
        } finally {
            if ($zip->status === ZipArchive::ER_OK) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }

    public function importZip(string $bytes, string $owner, string $email = '', bool $preserveIds = false): array
    {
        $parsed = $this->parseZip($bytes);
        $projectMeta = $parsed['project'];

        return $this->importDocuments(
            $parsed['documents'],
            trim((string) ($projectMeta['name'] ?? 'Projeto importado')),
            trim((string) ($projectMeta['description'] ?? '')),
            $owner,
            $email,
            $preserveIds,
            $projectMeta,
        );
    }

    public function importDocuments(
        array $documents,
        string $projectName,
        string $description,
        string $owner,
        string $email = '',
        bool $preserveIds = false,
        array $projectMeta = [],
    ): array {
        $clean = [];
        $warnings = [];
        $idMap = [];

        foreach ($documents as $key => $raw) {
            if (!is_array($raw)) {
                continue;
            }
            [$doc, $warn] = $this->documents->repairImport($raw, $owner);
            $old = trim((string) ($doc['flow']['id'] ?? $key));
            if ($old === '') {
                $old = 'flow_'.Str::lower(Str::random(12));
            }

            $canPreserve = $preserveIds && !isset($idMap[$old]) && !Flowchart::find($old);
            $new = $canPreserve ? $old : 'flow_'.Str::lower(Str::random(12));
            $idMap[$old] = $new;
            $clean[$old] = $doc;
            foreach ($warn as $warning) {
                $warnings[] = $old.': '.$warning;
            }
        }

        if (!$clean) {
            throw new \InvalidArgumentException('Nenhum documento de fluxo válido foi fornecido.');
        }

        $projectId = $preserveIds && !empty($projectMeta['id']) ? (string) $projectMeta['id'] : null;
        if ($projectId && Project::find($projectId)) {
            $projectId = null;
        }

        $project = $this->projects->create(
            $projectName ?: 'Projeto importado',
            $description,
            $owner,
            $email,
            (string) ($projectMeta['code'] ?? ''),
            $projectId,
            (array) ($projectMeta['tags'] ?? []),
            (array) ($projectMeta['settings'] ?? []),
        );

        $saved = [];
        $order = 0;
        foreach ($clean as $old => $doc) {
            $order++;
            $doc['flow']['id'] = $idMap[$old];
            $doc['flow']['projectId'] = (string) $project->_id;
            $doc['flow']['projectOrder'] = (int) ($doc['flow']['projectOrder'] ?? $order);
            $doc['flow']['projectRole'] = (string) ($doc['flow']['projectRole'] ?? 'subprocess');
            $doc['flow']['projectGroup'] = (string) ($doc['flow']['projectGroup'] ?? 'Geral');

            foreach ($doc['nodes'] ?? [] as &$node) {
                $linked = (string) ($node['data']['linkedFlowId'] ?? '');
                if ($linked !== '' && isset($idMap[$linked])) {
                    $node['data']['linkedFlowId'] = $idMap[$linked];
                }
            }
            unset($node);

            $flow = $this->flows->save($doc, $owner, $email, null, $owner, true, 'project_import');
            $flow->collaborators = [];
            $flow->visibility = 'private';
            $flow->save();
            $saved[] = $flow;
        }

        $oldDefault = (string) ($projectMeta['default_flow_id'] ?? $projectMeta['defaultFlowId'] ?? '');
        $project->default_flow_id = $idMap[$oldDefault] ?? ((string) ($saved[0]->_id ?? ''));
        $project->status = 'draft';
        $project->updated_at = now();
        $project->save();

        ActivityLogger::add($owner, 'Importou pacote de projeto', [
            'project_id' => (string) $project->_id,
            'flow_count' => count($saved),
            'warnings' => count($warnings),
        ]);

        return ['project' => $project, 'flows' => $saved, 'warnings' => $warnings, 'id_map' => $idMap];
    }

    private function zipEntries(ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $original = $zip->getNameIndex($index);
            if (!is_string($original) || $original === '') {
                continue;
            }
            $normalized = $this->normalizeZipPath($original);
            if ($normalized !== '') {
                $entries[$normalized] = $original;
            }
        }
        return $entries;
    }

    private function findProjectManifestEntry(array $entries): ?string
    {
        foreach (array_keys($entries) as $entry) {
            if (strtolower($entry) === 'project.json') {
                return $entry;
            }
        }

        $candidates = array_values(array_filter(array_keys($entries), fn ($name) => str_ends_with(strtolower($name), '/project.json')));
        if (!$candidates) {
            return null;
        }
        usort($candidates, fn ($a, $b) => strlen($a) <=> strlen($b));
        return $candidates[0];
    }

    private function firstExistingEntry(array $entries, array $candidates): ?string
    {
        $lowerMap = [];
        foreach (array_keys($entries) as $entry) {
            $lowerMap[strtolower($entry)] = $entry;
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = $this->normalizeZipPath((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            if (isset($entries[$candidate])) {
                return $candidate;
            }
            $lower = strtolower($candidate);
            if (isset($lowerMap[$lower])) {
                return $lowerMap[$lower];
            }
        }
        return null;
    }

    private function decodeJson(string $raw, string $label): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException($label.' contém JSON inválido: '.json_last_error_msg().'.');
        }
        return $decoded;
    }

    private function normalizeProjectMeta(array $project): array
    {
        if (empty($project['default_flow_id']) && !empty($project['defaultFlowId'])) {
            $project['default_flow_id'] = $project['defaultFlowId'];
        }
        if (empty($project['defaultFlowId']) && !empty($project['default_flow_id'])) {
            $project['defaultFlowId'] = $project['default_flow_id'];
        }
        return $project;
    }

    private function normalizeZipPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        while (str_contains($path, '//')) {
            $path = str_replace('//', '/', $path);
        }
        return ltrim($path, '/');
    }

    private function isFlowDocument(array $document): bool
    {
        return isset($document['flow'])
            || (isset($document['nodes']) && is_array($document['nodes']))
            || (isset($document['edges']) && is_array($document['edges']));
    }

    private function findFlowDefinition(array $definitions, string $oldId, string $relativeFile): array
    {
        $relativeFile = strtolower($this->normalizeZipPath($relativeFile));
        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $definitionId = trim((string) ($definition['id'] ?? $definition['flowId'] ?? $definition['flow_id'] ?? ''));
            if ($definitionId !== '' && $definitionId === $oldId) {
                return $definition;
            }
            $file = strtolower($this->normalizeZipPath((string) ($definition['file'] ?? '')));
            if ($file !== '' && $file === $relativeFile) {
                return $definition;
            }
        }
        return [];
    }

    private function applyManifestFlowMetadata(array &$document, array $definition, string $oldId): void
    {
        $document['flow'] = is_array($document['flow'] ?? null) ? $document['flow'] : [];
        if (empty($document['flow']['id']) && $oldId !== '') {
            $document['flow']['id'] = $oldId;
        }
        if (!array_key_exists('projectRole', $document['flow']) && !empty($definition['role'])) {
            $document['flow']['projectRole'] = (string) $definition['role'];
        }
        if (!array_key_exists('projectGroup', $document['flow']) && array_key_exists('group', $definition)) {
            $document['flow']['projectGroup'] = (string) ($definition['group'] ?? '');
        }
        if (!array_key_exists('projectOrder', $document['flow']) && isset($definition['order'])) {
            $document['flow']['projectOrder'] = (int) $definition['order'];
        }
    }
}
