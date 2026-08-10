<?php

namespace App\Domain\Monitoring\Protocols\Flow;

use Carbon\CarbonImmutable;
use RuntimeException;

final class NetFlowV9Decoder
{
    public function __construct(
        private readonly FlowTemplateRegistry $templates,
        private readonly FlowRecordDecoder $records = new FlowRecordDecoder,
    ) {}

    public function decode(string $packet, string $exporterAddress): FlowDatagram
    {
        if ($packet === '' || strlen($packet) > 65_507 || filter_var($exporterAddress, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('NetFlow v9 packet is invalid.');
        }
        $reader = new FlowBinaryReader($packet);
        if ($reader->uint16() !== 9) {
            throw new RuntimeException('NetFlow v9 version is unsupported.');
        }
        $reader->uint16();
        $uptime = $reader->uint32();
        $seconds = $reader->uint32();
        $sequence = $reader->uint32();
        $sourceId = $reader->uint32();
        if ($seconds < 1) {
            throw new RuntimeException('NetFlow v9 export timestamp is invalid.');
        }

        $records = [];
        while ($reader->remaining() > 0) {
            if ($reader->remaining() < 4) {
                throw new RuntimeException('NetFlow v9 flowset is truncated.');
            }
            $setId = $reader->uint16();
            $setLength = $reader->uint16();
            if ($setLength < 4 || $setLength - 4 > $reader->remaining()) {
                throw new RuntimeException('NetFlow v9 flowset is truncated.');
            }
            $set = $reader->subReader($setLength - 4);
            if ($setId === 0) {
                $this->decodeTemplates($set, $exporterAddress, $sourceId);
            } elseif ($setId === 1) {
                $set->skip($set->remaining());
            } elseif ($setId >= 256) {
                $template = $this->templates->resolve('netflow-v9', $exporterAddress, $sourceId, $setId);
                $this->decodeDataSet($set, $template, $records);
            } else {
                throw new RuntimeException('NetFlow v9 flowset identifier is invalid.');
            }
            $set->assertFinished(true);
        }

        return new FlowDatagram(
            family: 'netflow-v9',
            exporterAddress: $exporterAddress,
            sourceId: $sourceId,
            sequence: $sequence,
            uptimeMillis: $uptime,
            exportedAt: CarbonImmutable::createFromTimestampUTC($seconds),
            records: $records,
        );
    }

    private function decodeTemplates(FlowBinaryReader $reader, string $exporter, int $sourceId): void
    {
        while ($reader->remaining() > 3) {
            $templateId = $reader->uint16();
            $fieldCount = $reader->uint16();
            if ($templateId < 256 || $fieldCount < 1 || $fieldCount > 128) {
                throw new RuntimeException('NetFlow v9 template is invalid.');
            }
            $fields = [];
            for ($index = 0; $index < $fieldCount; $index++) {
                $wireType = $reader->uint16();
                $length = $reader->uint16();
                $enterprise = ($wireType & 0x8000) !== 0 ? $reader->uint32() : null;
                $fields[] = new FlowTemplateField($wireType & 0x7FFF, $length, $enterprise);
            }
            $this->templates->remember('netflow-v9', $exporter, $sourceId, new FlowTemplate($templateId, $fields));
        }
    }

    /** @param list<FlowRecord> $records */
    private function decodeDataSet(FlowBinaryReader $reader, FlowTemplate $template, array &$records): void
    {
        $fixedLength = $template->fixedRecordLength();
        if ($fixedLength === null || $fixedLength < 1) {
            throw new RuntimeException('NetFlow v9 variable-length template is unsupported.');
        }
        while ($reader->remaining() >= $fixedLength) {
            if (count($records) >= 1000) {
                throw new RuntimeException('NetFlow v9 record limit is exceeded.');
            }
            $records[] = $this->records->decode($reader->subReader($fixedLength), $template);
        }
    }
}
