export function calculateSupervisionScore(answers, questions, strategy) {
    let totalPoints = 0;
    let answeredCount = 0;

    for (const question of questions) {
        const value = answers[question.id];
        if (value === null || value === undefined || value === '') {
            continue;
        }

        const option = question.options.find((item) => String(item.id) === String(value));
        let points = option?.score ?? null;

        if (points === null && question.type === 'YES_NO') {
            const booleanValue = String(value).trim().toLowerCase();
            if (['1', 'true', 'yes', 'on'].includes(booleanValue)) {
                points = 4;
            } else if (['0', 'false', 'no', 'off'].includes(booleanValue)) {
                points = 0;
            }
        }

        if (points === null || !Number.isFinite(Number(points))) {
            continue;
        }

        totalPoints += Number(points);
        answeredCount++;
    }

    let finalScore = null;
    if (answeredCount > 0) {
        const average = totalPoints / answeredCount;
        finalScore = strategy === 'LINEAR_1_4'
            ? 1 + (totalPoints / (answeredCount * 4)) * 3
            : strategy === 'FLOOR_1_4' ? Math.max(1, average) : average;
        finalScore = Math.round(finalScore * 1e6) / 1e6;
    }

    return { totalPoints, answeredCount, totalCount: questions.length, finalScore };
}
