function copyCommitteeToClipboard(committeeId, committeeNumber) {
    var committeeDiv = document.getElementById(committeeId);
    if (!committeeDiv) {
        alert("Committee section not found");
        return;
    }

    var answerGroups = committeeDiv.getElementsByClassName("answer-group");
    var clipboardText = "Committee " + committeeNumber + "\\n";

    for (var i = 0; i < answerGroups.length; i++) {
        var questionNumber = answerGroups[i].getAttribute("data-question");
        var meeting = answerGroups[i].getAttribute("data-meeting");
        var status = answerGroups[i].getAttribute("data-status");
        var answer = answerGroups[i].getAttribute("data-answer");

        clipboardText += "\\nQuestion: " + questionNumber + "\\n";
        clipboardText += "Meeting: " + meeting + (status ? " - " + status : "") + "\\n";
        clipboardText += answer + "\\n";
        clipboardText += "\\n";
    }

    navigator.clipboard.writeText(clipboardText).then(function() {
        alert("Copied Committee " + committeeNumber + " to clipboard!");
    }, function(err) {
        console.error("Error copying text: ", err);
        alert("Failed to copy to clipboard. Please ensure you are using HTTPS.");
    });
}

function copyAllAnswersToClipboard(committeeNumber, questionNumber) {
    var committeeDiv = document.getElementById("committee_" + committeeNumber);
    if (!committeeDiv) {
        alert("Committee section not found");
        return;
    }

    var answerGroups = committeeDiv.getElementsByClassName("answer-group");
    var clipboardText = "All Answers for Committee " + committeeNumber + " - Question " + questionNumber + "\\n";

    for (var i = 0; i < answerGroups.length; i++) {
        var currentCommitteeNumber = answerGroups[i].getAttribute("data-committee");
        var currentQuestionNumber = answerGroups[i].getAttribute("data-question");

        if (currentCommitteeNumber == committeeNumber && currentQuestionNumber == questionNumber) {
            var meeting = answerGroups[i].getAttribute("data-meeting");
            var status = answerGroups[i].getAttribute("data-status");
            var answer = answerGroups[i].getAttribute("data-answer");
            clipboardText += "\\nMeeting: " + meeting + (status ? " - " + status : "") + "\\n";
            clipboardText += answer + "\\n";
            clipboardText += "\\n";
        }
    }

    navigator.clipboard.writeText(clipboardText).then(function() {
        alert("Copied all answers for Committee " + committeeNumber + " - Question " + questionNumber + " to clipboard!");
    }, function(err) {
        console.error("Error copying text: ", err);
        alert("Failed to copy to clipboard. Please ensure you are using HTTPS.");
    });
}

function confurReportingRefresh() {
    location.reload();
}

function confurReportingExportCSV() {
    alert("CSV export functionality can be implemented based on specific requirements.");
}
