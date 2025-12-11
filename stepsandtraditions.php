<?php

function generate_step($atts = array(), $content) {

	$output = '';
	$number = trim($atts['number']);

	switch ($number) {
		case 1:
			$output = '1. "We admitted we were powerless over alcohol — that our lives had become unmanageable."';
			break;
		case 2:
			$output = '2. "Came to believe that a Power greater than ourselves could restore us to sanity."';
			break;
		case 3:
			$output = '3. "Made a decision to turn our will and our lives over to the care of God as we understood Him."';
			break;
		case 4:
			$output = '4. "Made a searching and fearless moral inventory of ourselves."';
			break;
		case 5:
			$output = '5. "Admitted to God, to ourselves, and to another human being the exact nature of our wrongs."';
			break;
		case 6:
			$output = '6. "Were entirely ready to have God remove all these defects of character."';
			break;
		case 7:
			$output = '7. "Humbly asked Him to remove our shortcomings."';
			break;
		case 8:
			$output = '8. "Made a list of all persons we had harmed, and became willing to make amends to them all."';
			break;
		case 9:
			$output = '9. "Made direct amends to such people wherever possible, except when to do so would injure them or others."';
			break;
		case 10:
			$output = '10. "Continued to take personal inventory and when we were wrong promptly admitted it."';
			break;
		case 11:
			$output = '11. "Sought through prayer and meditation to improve our conscious contact with God as we understood Him, praying only for knowledge of His will for us and the power to carry that out."';
			break;
		case 12:
			$output = '12. "Having had a spiritual awakening as the result of these Steps, we tried to carry this message to alcoholics, and to practice these principles in all our affairs."';
			break;
	}

	$params['url'] = $log_form_url = 'https://www.aa.org/sites/default/files/2022-01/en_step' . $number . '.pdf';
	$params['name'] = 'en_step' . $number . '.pdf';

	return 'Step ' . $output . ' ' . generate_pdf_link($params, '(Long Form)');

}

function generate_tradition($atts = array(), $content) {

	$output = '';
	$number = trim($atts['number']);

	switch ($number) {
		case 1:
			$output = '1. "Our common welfare should come first; personal recovery depends upon A.A. unity."';
			break;
		case 2:
			$output = '2. "For our group purpose there is but one ultimate authority—a loving God as He may express Himself in our group conscience. Our leaders are but trusted servants; they do not govern."';
			break;
		case 3:
			$output = '3. "The only requirement for A.A. membership is a desire to stop drinking."';
			break;
		case 4:
			$output = '4. "Each group should be autonomous except in matters affecting other groups or A.A. as a whole."';
			break;
		case 5:
			$output = '5. "Each group has but one primary purpose—to carry its message to the alcoholic who still suffers."';
			break;
		case 6:
			$output = '6. "An A.A. group ought never endorse, finance, or lend the A.A. name to any related facility or outside enterprise, lest problems of money, property, and prestige divert us from our primary purpose."';
			break;
		case 7:
			$output = '7. "Every A.A. group ought to be fully self-supporting, declining outside contributions."';
			break;
		case 8:
			$output = '8. "Alcoholics Anonymous should remain forever nonprofessional, but our service centers may employ special workers."';
			break;
		case 9:
			$output = '9. "A.A., as such, ought never be organized; but we may create service boards or committees directly responsible to those they serve."';
			break;
		case 10:
			$output = '10. "Alcoholics Anonymous has no opinion on outside issues; hence the A.A. name ought never be drawn into public controversy."';
			break;
		case 11:
			$output = '11. "Our public relations policy is based on attraction rather than promotion; we need always maintain personal anonymity at the level of press, radio, and films."';
			break;
		case 12:
			$output = '12. "Anonymity is the spiritual foundation of all our Traditions, ever reminding us to place principles before personalities."';
			break;
	}

	$params['url'] = $log_form_url = 'https://www.aa.org/sites/default/files/2022-01/en_tradition' . $number . '.pdf';
	$params['name'] = 'en_tradition' . $number . '.pdf';

	return 'Tradition ' . $output . ' ' . generate_pdf_link($params, '(Long Form)');

}