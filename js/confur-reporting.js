
function copyCommitteeToClipboard(committeeId, committeeNumber) {
    var committeeDiv = document.getElementById(committeeId);
    var answerGroups = committeeDiv.getElementsByClassName('answer-group');
    var clipboardText = 'Committee ' + committeeNumber + '\\n';

    for (var i = 0; i < answerGroups.length; i++) {
        var questionNumber = answerGroups[i].getAttribute('data-question');
        var meeting = answerGroups[i].getAttribute('data-meeting');
        var answer = answerGroups[i].getAttribute('data-answer');

        clipboardText += '\\nQuestion: ' + questionNumber + '\\n';
        clipboardText += 'Meeting: ' + meeting + '\\n';
        clipboardText += answer + '\\n';  // Answer is added directly
        clipboardText += '\\n'; // Add blank line between answers
    }

    navigator.clipboard.writeText(clipboardText).then(function() {
        alert('Copied Committee ' + committeeNumber + ' to clipboard!');
    }, function(err) {
        console.error('Error copying text: ', err);
    });
}

function copyAllAnswersToClipboard(committeeNumber, questionNumber) {
    var committeeDiv = document.getElementById('committee_' + committeeNumber);
    var answerGroups = committeeDiv.getElementsByClassName('answer-group');
    var clipboardText = 'All Answers for Committee ' + committeeNumber + ' - Question ' + questionNumber + '\\n';

    for (var i = 0; i < answerGroups.length; i++) {
        var currentCommitteeNumber = answerGroups[i].getAttribute('data-committee');
        var currentQuestionNumber = answerGroups[i].getAttribute('data-question');

        if (currentCommitteeNumber == committeeNumber && currentQuestionNumber == questionNumber) {
            var meeting = answerGroups[i].getAttribute('data-meeting');
            var answer = answerGroups[i].getAttribute('data-answer');

            clipboardText += '\\nMeeting: ' + meeting + '\\n';
            clipboardText += answer + '\\n';  // Answer is added directly
            clipboardText += '\\n'; // Add blank line between answers
        }
    }

    navigator.clipboard.writeText(clipboardText).then(function() {
        alert('Copied all answers for Committee ' + committeeNumber + ' - Question ' + questionNumber + ' to clipboard!');
    }, function(err) {
        console.error('Error copying text: ', err);
    });
}
