import assert from 'node:assert/strict';
import { test } from 'node:test';
import { calculateSupervisionScore } from '../../resources/js/supervision-score.js';

const questions = [
    { id: 1, type: 'SCORE', options: [{ id: 11, score: 0 }, { id: 12, score: 1 }, { id: 13, score: 4 }] },
    { id: 2, type: 'SCORE', options: [{ id: 21, score: 2 }, { id: 22, score: 3 }] },
    { id: 3, type: 'YES_NO', options: [{ id: 31, score: 0 }, { id: 32, score: 4 }] },
];

test('an empty form has no provisional score under any policy', () => {
    for (const strategy of ['MEAN_0_4', 'LINEAR_1_4', 'FLOOR_1_4']) {
        assert.deepEqual(calculateSupervisionScore({ 1: '', 2: null }, questions, strategy), {
            totalPoints: 0, answeredCount: 0, totalCount: 3, finalScore: null,
        });
    }
});

test('saved answers, zero scores, and changed selections immediately affect the average', () => {
    const answers = { 1: '11', 2: '22' };
    assert.equal(calculateSupervisionScore(answers, questions, 'MEAN_0_4').finalScore, 1.5);
    answers[1] = '13';
    assert.equal(calculateSupervisionScore(answers, questions, 'MEAN_0_4').finalScore, 3.5);
    answers[2] = '';
    assert.equal(calculateSupervisionScore(answers, questions, 'MEAN_0_4').finalScore, 4);
    answers[1] = '11';
    const score = calculateSupervisionScore(answers, questions, 'MEAN_0_4');
    assert.equal(score.finalScore, 0);
    assert.equal(score.answeredCount, 1);
});

test('only valid options belonging to the scored question are included', () => {
    const score = calculateSupervisionScore({ 1: 22, 2: 21, 3: 999, 4: 'Catatan', 5: 4 }, questions, 'MEAN_0_4');
    assert.equal(score.finalScore, 2);
    assert.equal(score.answeredCount, 1);
    assert.equal(score.totalPoints, 2);
});

test('the selected scoring strategy is applied to the answered items', () => {
    const answers = { 1: 11, 2: 21 };
    assert.equal(calculateSupervisionScore(answers, questions, 'MEAN_0_4').finalScore, 1);
    assert.equal(calculateSupervisionScore(answers, questions, 'LINEAR_1_4').finalScore, 1.75);
    assert.equal(calculateSupervisionScore({ 1: 11 }, questions, 'FLOOR_1_4').finalScore, 1);
    assert.equal(calculateSupervisionScore({ 1: 13, 2: 22 }, questions, 'FLOOR_1_4').finalScore, 3.5);
});

test('yes/no options and legacy boolean answers preserve false as a zero score', () => {
    for (const answer of [31, false, 0, '0', 'false']) {
        const score = calculateSupervisionScore({ 3: answer }, questions, 'MEAN_0_4');
        assert.equal(score.finalScore, 0);
        assert.equal(score.answeredCount, 1);
    }
    assert.equal(calculateSupervisionScore({ 3: 32 }, questions, 'MEAN_0_4').finalScore, 4);
    assert.equal(calculateSupervisionScore({ 3: true }, questions, 'MEAN_0_4').finalScore, 4);
});

test('fractional scores use the same six-decimal precision as the server', () => {
    assert.equal(calculateSupervisionScore({ 1: 12, 2: 21, 3: 32 }, questions, 'MEAN_0_4').finalScore, 2.333333);
});
