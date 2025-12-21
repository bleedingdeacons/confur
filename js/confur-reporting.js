
function copyCommitteeToClipboard(committeeId, committeeNumber) {
    const committeeDiv = document.getElementById(committeeId);
    if (!committeeDiv) {
        alert("Committee section not found");
        return;
    }

    const answerGroups = committeeDiv.getElementsByClassName("answer-group");
    let clipboardText = "Committee " + committeeNumber + "\n";

    for (let i = 0; i < answerGroups.length; i++) {
        const questionNumber = answerGroups[i].getAttribute("data-question");
        const meeting = answerGroups[i].getAttribute("data-meeting");
        const status = answerGroups[i].getAttribute("data-status");
        const answer = answerGroups[i].getAttribute("data-answer");

        clipboardText += "\nQuestion: " + questionNumber + "\n";
        clipboardText += "Meeting: " + meeting + (status ? " - " + status : "") + "\n";
        clipboardText += answer + "\n";
        clipboardText += "\n";
    }

    navigator.clipboard.writeText(clipboardText).then(function() {
        alert("Copied Committee " + committeeNumber + " to clipboard!");
    }, function(err) {
        console.error("Error copying text: ", err);
        alert("Failed to copy to clipboard. Please ensure you are using HTTPS.");
    });
}

function copyAllAnswersToClipboard(committeeNumber, questionNumber) {
    const committeeDiv = document.getElementById("committee_" + committeeNumber);
    if (!committeeDiv) {
        alert("Committee section not found");
        return;
    }

    const answerGroups = committeeDiv.getElementsByClassName("answer-group");
    let clipboardText = "All Answers for Committee " + committeeNumber + " - Question " + questionNumber + "\n";

    for (let i = 0; i < answerGroups.length; i++) {
        const currentCommitteeNumber = answerGroups[i].getAttribute("data-committee");
        const currentQuestionNumber = answerGroups[i].getAttribute("data-question");

        if (currentCommitteeNumber == committeeNumber && currentQuestionNumber == questionNumber) {
            const meeting = answerGroups[i].getAttribute("data-meeting");
            const status = answerGroups[i].getAttribute("data-status");
            const answer = answerGroups[i].getAttribute("data-answer");
            clipboardText += "\nMeeting: " + meeting + (status ? " - " + status : "") + "\n";
            clipboardText += answer + "\n";
            clipboardText += "\n";
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