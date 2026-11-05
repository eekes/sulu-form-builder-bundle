<?php

declare(strict_types=1);

namespace Eekes\Sulu\FormBuilderBundle\Domain\Model;

/**
 * One answer of a submission, with the label the visitor saw at the time.
 *
 * Forms change after they went live. Keeping the label, the type and a readable rendering of the
 * value with every answer means an old submission still reads correctly after a field was
 * renamed, moved or deleted.
 */
final readonly class SubmittedValue
{
    /**
     * @param mixed $value JSON-safe: a scalar, null, a list of scalars, or a list of stored file arrays
     * @param list<StoredFile> $files
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public mixed $value,
        public string $display,
        public bool $showInList = false,
        public array $files = [],
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $key = $data['key'] ?? null;

        if (!\is_string($key)) {
            return null;
        }

        $files = [];

        foreach (\is_array($data['files'] ?? null) ? $data['files'] : [] as $file) {
            $file = StoredFile::fromArray($file);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        return new self(
            key: $key,
            label: \is_string($data['label'] ?? null) ? $data['label'] : $key,
            type: \is_string($data['type'] ?? null) ? $data['type'] : 'text',
            value: $data['value'] ?? null,
            display: \is_string($data['display'] ?? null) ? $data['display'] : '',
            showInList: (bool) ($data['showInList'] ?? false),
            files: $files,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'value' => $this->value,
            'display' => $this->display,
            'showInList' => $this->showInList,
            'files' => array_map(static fn (StoredFile $file): array => $file->toArray(), $this->files),
        ];
    }

    public function withKey(string $key): self
    {
        return new self($key, $this->label, $this->type, $this->value, $this->display, $this->showInList, $this->files);
    }

    public function withoutFiles(): self
    {
        return new self($this->key, $this->label, $this->type, $this->value, $this->display, $this->showInList);
    }

    public function isEmpty(): bool
    {
        return $this->value === null || $this->value === '' || $this->value === [];
    }
}
