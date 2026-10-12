@foreach(\App\Services\Supervision\EvaluationNotes::LABELS as $noteKey => $noteLabel)
    @if(trim((string) ($evaluationNotes[$noteKey] ?? '')) !== '')
        <div style="margin-bottom: 6px;">
            <strong>{{ $noteLabel }}:</strong>
            <div style="white-space: pre-line;">{{ $evaluationNotes[$noteKey] }}</div>
        </div>
    @endif
@endforeach
