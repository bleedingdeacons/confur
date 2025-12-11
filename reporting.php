<?php

function generate_report() {

	$meetings = get_meetings2();

	$registered = get_registered_groups();

	$answers = get_group_answers();

	$all_answer_table = generate_answer_table2($answers);

	$unregistered_table = generate_unregistered_table($meetings, $answers, $registered);

	$registered_table = generate_registered_table($registered, $meetings);

	$coverage = generate_coverage_table($answers);

	$links_table = generate_links_table();

	$output = "<h1>Reporting</h1>{$links_table}<h2 id=\"answer_table\">Answers</h2>{$all_answer_table}<h2 id=\"coverage\">Coverage</h2>{$coverage}<h2 id=\"registration\">Registration</h2><h3 id=\"registered\">Registered</h3>{$registered_table}<h3 id=\"unregistered\">Unregistered</h3>{$unregistered_table}";

	return $output;

}

function generate_coverage_table($data) {

	$results = [];

	// Process data to calculate total responses, average word count, lowest and highest word count
	foreach ($data as $committee_answer => $answers) {
		list($committee, $answer) = explode("_", $committee_answer); // Split committee and answer
		$committee = ltrim($committee, "c"); // Remove "c" prefix from committee
		$answer = ltrim($answer, "a"); // Remove "a" prefix from answer

		$wordCounts = [];
		foreach ($answers as $response) {
			$wordCounts[] = str_word_count($response[5]); // Count words in the response
		}

		$responseCount = count($answers);
		$totalWords = array_sum($wordCounts);
		$avgWordCount = $responseCount > 0 ? $totalWords / $responseCount : 0;
		$minWordCount = $responseCount > 0 ? min($wordCounts) : 0;
		$maxWordCount = $responseCount > 0 ? max($wordCounts) : 0;

		$results[] = [
			"committee" => $committee,
			"answer" => $answer,
			"response_count" => $responseCount,
			"average_word_count" => round($avgWordCount, 2),
			"lowest_word_count" => $minWordCount,
			"highest_word_count" => $maxWordCount
		];
	}

	// Sort results by committee and answer
	usort($results, function ($a, $b) {
		return $a['committee'] <=> $b['committee'] ?: $a['answer'] <=> $b['answer'];
	});

	// Create HTML table and store it in a variable
	$html = "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
	$html .= "<tr><th>Committee</th><th>Answer</th><th>Response Count</th><th>Average Word Count</th><th>Lowest Word Count</th><th>Highest Word Count</th></tr>";

	foreach ($results as $result) {

		$link = create_answer_link($result['committee'], $result['answer'], 'Answer ' . $result['answer']);

		$html .= "<tr>";
		$html .= "<td>Committee {$result['committee']}</td>";
		$html .= "<td>{$link}</td>";
		$html .= "<td>{$result['response_count']}</td>";
		$html .= "<td>{$result['average_word_count']}</td>";
		$html .= "<td>{$result['lowest_word_count']}</td>";
		$html .= "<td>{$result['highest_word_count']}</td>";
		$html .= "</tr>";
	}

	$html .= "</table>";

	// Output the table
	return $html;
}

function generate_links_table() {

	$html = '<table id="answer_links"><tbody>';

	$group = '<strong>Navigation</strong><ul><li><a href="#registered">Registered</a></li><li><a href="#unregistered">Unregistered</a></li><li><a href="#coverage">Coverage</a></li></ul>';

	$committee1 = create_answer_links(1, 4);

	$committee2 = create_answer_links(2, 4);

	$committee3 = create_answer_links(3, 4);

	$committee4 = create_answer_links(4, 4);

	$committee5 = create_answer_links(5, 3);

	$committee6 = create_answer_links(6, 2);

	$class = 'class="answerLinks"';

	$html .= "<tr><td {$class}>{$group}</td><td {$class}>{$committee1}</td><td {$class}>{$committee2}</td><td {$class}>{$committee3}</td><td {$class}>{$committee4}</td><td {$class}>{$committee5}</td><td {$class}>{$committee6}</td></tr>";

// 	$html .= '<tr><td colspan=6><strong>Group Registration </strong><a href="#unregistered">Unregistered</a> <a href="#registered"> Registered</a></td></tr>';

	$html .= "</tbody></table>";

	return $html;

}

function create_answer_links($committeeNumber, $answerCount) {

	$html = "<strong>Committee {$committeeNumber}</strong><ul>";

	for ($count = 1; $count <=$answerCount; $count++) {

		$link = create_answer_link($committeeNumber, $count, 'Answer ' . $count);
		$html .= "<li>{$link}</li>";

	}

	$html .= '</ul>';

	return $html;

}

function create_answer_link($committeeNumber, $answerNumber, $content) {

	return "<a href=\"#c{$committeeNumber}_a{$answerNumber}\">{$content}</a>";
}

function generate_registered_table($registered, $meetings) {

	$total_meetings = count($meetings);
	$registered_count = get_unique_count_of($registered, 'meeting');
	$percentage_registered = percentage($registered_count, $total_meetings);

	$html_summary = "<p>Total Meetings: <strong>{$registered_count}</strong> out of <strong>{$total_meetings}</strong> (Approx {$percentage_registered}%)</p>";
	$html = '<table border="1" cellspacing="0" cellpadding="5">';
	$html .= '<tr><th>Name</th><th>Status</th><th>Updated</th><th>Email</th><th>Contact 1</th><th>Contact 2</th></tr>';

	// Track status counts
	$status_counts = [];

	foreach ($registered as $item) {
		$meeting_name = get_the_title($item['meeting']);
		$meeting_url = get_permalink($item['answers']);
		$updated = trim($item['updated']);
		$status = isset($item['state']) && !empty($item['state']) ? $item['state'] : 'Not Started';

		if (empty($updated)) {
			$updated = "Not Started";
		}

		$contacts = get_meeting_contacts($item['meeting']);

		$contact1 = isset($contacts[0]) ? '<td>' . contact_telephone_link($contacts[0]) . '</td>' : '<td>-</td>';
		$contact2 = isset($contacts[1]) ? '<td>' . contact_telephone_link($contacts[1]) . '</td>' : '<td>-</td>';

		$email_link = !empty($item['email'])
			? create_link(create_email_to_address($item['email'], "Questions for Conference"), '', $item['email'])
			: '-';

		$meetng_link = create_link($meeting_url, '', $meeting_name);

		$html .= "<tr><td>{$meetng_link}</td><td>{$status}</td><td>{$updated}</td><td>{$email_link}</td>$contact1 $contact2</tr>";

		// Count the status occurrences
		$status_counts[$status] = isset($status_counts[$status]) ? $status_counts[$status] + 1 : 1;
	}

	$html .= "</table>";


	$status_html = '<table border="1" cellspacing="0" cellpadding="5">';
	$status_html .= '<thead><tr>';

	// Create headers from status names
	foreach ($status_counts as $status => $count) {
		$status_html .= "<th>{$status}</th>";
	}
	$status_html .= '</tr></thead><tbody><tr>';

	// Create values in a single row
	foreach ($status_counts as $count) {
		$status_html .= "<td>{$count}</td>";
	}

	$status_html .= "</tr></tbody></table>";

	return $html_summary. $status_html . $html;
}


function get_unique_count_of($array, $field) {

	$things = array_column($array, $field);

	// Count occurrences of each name
	$counts = array_count_values($things);

	// Count distinct names (appearing only once)
	$distinctCount = count(array_filter($counts, function ($count) {
		return $count == 1;
	}));

	return $distinctCount;
}

function contact_telephone_link($contact) {

	return $contact['name'] . ' ' . create_link(create_phone_to_address($contact['phone']),'', $contact['phone']);

}

function generate_unregistered_table($meetings, $answers, $registered) {

	$registered_meetings = array_unique(array_column($registered, "meeting"));

	$unregistered = array_filter($meetings, function ($item) use ($registered_meetings) {
		return !in_array($item["id"], $registered_meetings);
	});

	$total_meetings = count($meetings);

	$unregistered_count = count($unregistered);

	$percentage_unregistered = percentage($unregistered_count, $total_meetings);

	$html = "<p>Total Meetings: <strong>{$unregistered_count}</strong> out of <strong>{$total_meetings}</strong> (Approx {$percentage_unregistered}%)</p>";
	$html .= '<table>';
	$html .= '<tr><th>Name</th><th>Contact 1</th><th>Contact 2</th><th>Online</th></tr>';

	foreach ($unregistered as $item) {

		$contacts = get_meeting_contacts($item['id']);

// 		pretty_print($contacts);

		$contact1 = '<td>' . contact_telephone_link($contacts[0])  . '</td>';

		$contact2 = '<td>' . contact_telephone_link($contacts[1])  . '</td>';

		$html .= '<tr><td><a href=' . $item['url'] . ' target="_blank">' . $item['name'] . '</a></td>' . $contact1 . $contact2 . '<td>' . ($item['online'] ? 'Yes' : 'No') . '</td></tr>';

	}

	$html .= "</table>";


	return $html;

}

function percentage($x, $y) {
	if ($y == 0) {
		return "Undefined (division by zero)";
	}
	return floor(($x / $y) * 100);
}

// function generate_answer_table ($answers) {

// 	$html = '<table border="1" cellpadding="5" cellspacing="0">';
// 	$html .= '<thead><tr><th>Committee</th><th>Answer</th><th>Details</th></tr></thead><tbody>';

// 	foreach ($answers as $key => $rows) {
// 		// Extract committee number and answer number
// 		[$committeeNumber, $answerNumber] = explode('_', $key);
// 		$committeeNumber = str_replace('c', 'Committee ', $committeeNumber);
// 		$answerNumber = str_replace('a', 'Answer ', $answerNumber);

// 		// Add a row for the group
// 		$html .= "<tr><td>$committeeNumber</td><td id=\"$key\">$answerNumber</td><td>";

// 		// Process each sub-array
// 		foreach ($rows as $row) {
// 			if (STATUS_CANCELLED != $row[6]) {
// 				$time = $row[4];
// 				$meeting = $row[1];
// 				$email = $row[3];
// 				$answer = $row[5];

// 				$link = create_link(create_email_to_address($email, "Questions for Conference"), '', $email);

// 				// Add details for each item with index
// 				$html .= "<div><strong>Updated:</strong> $time</div>";
// 				$html .= "<div><strong>Meeting:</strong> $meeting</div>";
// 				$html .= "<div><strong>Email:</strong> $link</div>";
// 				$html .= "<div><strong>Answer:</strong><div class=\"answer\">$answer</div></div><hr>";
// 			}
// 		}

// 		$html .= '</td></tr>';
// 	}

// 	$html .= '</tbody></table>';

// 	return $html;


// }


function get_registered_groups() {

	$all = get_all_answers();

// 	pretty_print($all);

	$registered = array();

	foreach ($all as $post_id) {

		$meeting = get_field(MEETING_FIELD, $post_id);

		if(!empty($meeting)) {

			$email = get_field(EMAIL_FIELD, $post_id);

			$updated = get_field(UPDATED_FIELD, $post_id);

			$state = get_answer_status($post_id)['state'];

// 			error_log('Status'. $state);

			$registered[] = ['answers' => $post_id, 'meeting' => $meeting, 'email' => $email, 'updated' => $updated, 'state' => $state];
		}
	}

	usort($registered, function ($a, $b) {
		return strcmp($a['meeting'], $b['meeting']);
	});

	return $registered;

}

function get_group_answers() {

	$answers = array();

	$all = get_all_answers();

// 	pretty_print($all);

	foreach ($all as $post_id) {

		$meeting = get_field(MEETING_FIELD, $post_id);

		$email = get_field(EMAIL_FIELD, $post_id);

		$updated = get_field(UPDATED_FIELD, $post_id);

		$status = get_field(STATUS_FIELD, $post_id);

		if (!empty($updated)) {

			$all_fields = get_fields($post_id);

// 			pretty_print($all_fields);

			foreach ($all_fields as $field_name => $field_value) {

				if (str_starts_with($field_name, 'c')) {

					foreach ($field_value as $question_name => $answer) {

						if (!empty($answer)) {

							$meeting_name = get_the_title($meeting);

							$result_url = get_permalink($post_id);

							$group_answer = [$meeting, $meeting_name, $result_url, $email, $updated, $answer, $status];

							$answers[$field_name . '_'  . $question_name][] = $group_answer;

						}

					}

				}

			}
		}
	}

//  	pretty_print($answers);

	return $answers;

}

function get_all_answers() {

	$all = get_posts(array(
		'post_type'      => ANSWER_CUSTOM_TYPE,
		'posts_per_page' => -1,
		'fields' => 'ids'
	));

//  	pretty_print($all);

	return array_filter($all);

}

function generate_answer_table2($answers) {
	$html = '<style>
        .committee-header {
            background-color: #d9e1f2; 
            font-weight: bold; 
        }
        .copy-btn {
            margin-left: 10px; 
            padding: 5px;
            cursor: pointer;
            background-color: #4CAF50; 
            color: white; 
            border: none;
            border-radius: 5px;
        }
        .copy-all-answers-btn {
            margin-left: 10px; 
            padding: 5px;
            cursor: pointer; 
            background-color: #4CAF50; 
            color: white; 
            border: none;
            border-radius: 5px;
            font-size: 12px;
        }
        .answer-group {
            border: 1px solid #ccc; 
            padding: 10px; 
            margin-bottom: 15px; 
            background-color: #f9f9f9;
        }
        .question-header {
            background-color: #f2f2f2; 
            font-weight: bold; 
            padding: 5px;
        }
    </style>';

	$html .= '<table id="all_answers" border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
	$grouped_answers = [];
	$addedAnchors = []; // Track first unique committee-question pairs

	// Group answers by committee and question
	foreach ($answers as $key => $rows) {
		[$committeeNumber, $questionNumber] = explode('_', $key);
		$committeeNumber = str_replace('c', '', $committeeNumber);
		$questionNumber = str_replace('a', '', $questionNumber);

		if (!isset($grouped_answers[$committeeNumber])) {
			$grouped_answers[$committeeNumber] = [];
		}

		$grouped_answers[$committeeNumber][$questionNumber] = $rows;
	}

	// Sort committees numerically
	ksort($grouped_answers);

	foreach ($grouped_answers as $committeeNumber => $questionsByCommittee) {
		$committeeId = "committee_{$committeeNumber}";

		// Committee Header with Copy Buttons
		$html .= "<tr><td colspan='2' class='committee-header'>
                    Committee {$committeeNumber} 
                    <button class='copy-btn' onclick=\"copyCommitteeToClipboard('{$committeeId}', {$committeeNumber})\">📋 Copy Committee</button>";

		// Add a "Copy All Answers" button for each question
		foreach ($questionsByCommittee as $questionNumber => $rows) {
			$html .= "<button class='copy-all-answers-btn' onclick=\"copyAllAnswersToClipboard('{$committeeNumber}', {$questionNumber})\">Copy All Answers {$questionNumber}</button>";
		}

		$html .= "</td></tr>";

		// Wrap committee content inside a div for easy copying
		$html .= "<tr><td colspan='2'><div id='{$committeeId}'>";

		foreach ($questionsByCommittee as $questionNumber => $rows) {
			foreach ($rows as $index => $row) {
				if (STATUS_CANCELLED != $row[6]) {
					$time = $row[4];
					$meeting = $row[1];
					$email = $row[3];
					$answer = $row[5];

					// Check if an anchor is needed for this committee-question pair
					$anchorKey = "c{$committeeNumber}_a{$questionNumber}";
					$anchorId = "";
					if (!isset($addedAnchors[$anchorKey])) {
						$anchorId = "id='{$anchorKey}'";
						$addedAnchors[$anchorKey] = true; // Mark this pair as having an anchor
					}

					// Answer data for clipboard (hidden div)
					$html .= "<div class='answer-group' $anchorId data-committee='{$committeeNumber}' data-question='{$questionNumber}' data-meeting='{$meeting}' data-answer='{$answer}'>";

					$html .= "<div class='question-header'>{$meeting}</div>";

					// Committee and Question Number
					$html .= "<div><strong>Committee {$committeeNumber} - Question {$questionNumber}</strong></div>";

					// Keep "Updated" and "Email" in the table
					$html .= "<div><strong>Updated:</strong> {$time}</div>";
					$html .= "<div><strong>Email:</strong> {$email}</div>";

					// Answer Details
					$html .= "<div><strong>Answer:</strong><div class=\"answer\">{$answer}</div></div>";

					$html .= "</div>"; // Close answer-group
				}
			}
		}

		// Close div
		$html .= "</div></td></tr>";
	}

	$html .= '</table>';

	// JavaScript for formatted clipboard copying (without "Updated", "Email", or "Committee X - Question Y" under Meeting)
	$html .= "<script>
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
    </script>";

	return $html;
}