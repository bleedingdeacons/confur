<?php

if (!function_exists('get_meeting_contacts')) {
	function get_meeting_contacts( $post_id ) {

		$contacts = array();

// 	pretty_print($post_id);

		$meeting_meta = get_post_custom( $post_id );

// 	pretty_print($meeting_meta);

		for ( $count = 1; $count <= 3; $count ++ ) {

			$key = "contact_{$count}_name";

			$name = $meeting_meta["contact_{$count}_name"][0];

			if ( ! empty( $name ) ) {

				$phone = $meeting_meta["contact_{$count}_phone"][0];

				$email = $meeting_meta["contact_{$count}_email"][0];

				$contact = [ 'name' => $name, 'phone' => $phone, 'email' => $email ];

				$contacts[] = $contact;

			}

		}

// 	pretty_print($contacts);

		return $contacts;
	}
}

if (!function_exists('get_meetings2')) {
	function get_meetings2() {

		$posts = get_posts( [
			'post_type'   => 'tsml_meeting',
			'numberposts' => - 1,
			'post_status' => 'publish',
		] );

		foreach ( $posts as $post ) {

			$meeting_meta = get_post_custom( $post->ID );

			$meeting = [
				'id'       => $post->ID,
				'name'     => $post->post_title,
				'slug'     => $post->post_name,
				'location' => get_the_title( $post->post_parent ),
				'url'      => get_permalink( $post->ID ),
				'day'      => $meeting_meta['day'][0],
				'time'     => $meeting_meta['time'][0],
				'end_time' => $meeting_meta['end_time'][0],
				//'time_formatted' => isset($meeting_meta['time']) ? tsml_format_time($meeting_meta['time']) : null,
				'online'   => is_online( empty( $meeting_meta['types'] ) ? [] : unserialize( $meeting_meta['types'][0] ) )
			];

// 		pretty_print($meeting);

			$meetings[] = $meeting;

		}

		return array_reverse( $meetings );
	}
}

if (!function_exists('send_custom_email')) {
	function send_custom_email( $recipient_email, $from, $subject, $params = [] ) {

		error_log( 'send_custom_email' );

		$email_template = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .email-content { padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
                .footer { margin-top: 20px; font-size: 12px; color: #777; }
            </style>
        </head>
        <body>
            {{content}}
        </body>
        </html>
    ';

		foreach ( $params as $key => $value ) {
			$email_template = str_replace( "{{{$key}}}", $value, $email_template );
		}

		//error_log(serialize($email_template));

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from
		];

		$is_sent = wp_mail( $recipient_email, $subject, $email_template, $headers );

		return $is_sent;
	}
}

if (!function_exists('generate_pdf_link')) {
	function generate_pdf_link( $atts, $content ) {

		return '<a href="' . esc_attr( $atts['url'] ) . '" download="' . esc_attr( $atts['name'] ) . '" type="application/pdf" target="_blank" rel="noreferrer noopener">' . $content . '</a>';

	}
}


if (!function_exists('pretty_print')) {
	function pretty_print( $array_data ) {
		print( "<pre>" . print_r( $array_data, true ) . "</pre>" );
	}
}

if (!function_exists('create_email_to_address')) {
	function create_email_to_address( $address, $subject = null ) {

		if ( ! empty( $subject ) ) {
			$address = $address . '?subject=' . $subject;
		}

		return 'mailto:' . $address;
	}
}

if (!function_exists('create_email_anchor')) {
	function create_email_anchor( $address, $subject = null, $content ) {

		$target = create_email_to_address( $address, $subject );

		return '<a href="' . esc_attr( $target ) . '">' . $content . '</a>';

	}
}

if (!function_exists('create_phone_to_address')) {

	function create_phone_to_address( $number ) {

		return 'tel:' . $number;
	}
}

if (!function_exists('getToday')) {
	function getToday() {

		return new DateTime( "now" );

	}
}

if (!function_exists('getDateFrom')) {

	function getDateFrom( $string, $format = DATE_FORMAT ) {

		return DateTimeImmutable::createFromFormat( $format, $string );

	}
}

if (!function_exists('getBool')) {
	function getBool( $value ) {

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}

if (!function_exists('open_blank')) {
	function open_blank( $atts = array(), $content = null ) {

		$a = shortcode_atts( array(
			'href'  => '#',
			'class' => ''
		), $atts );

		return create_link( esc_attr( $a['href'], '' ), esc_attr( $a['class'], '' ), $content );
	}
}

if (!function_exists('create_link')) {
	function create_link( $href, $class, $content = null ) {

		return '<a target="_blank" rel="noreferrer noopener" class="' . $class . '" href="' . $href . '">' . $content . '</a>';
	}
}

if ( ! function_exists( 'link_email' ) ) {
	function link_email( $atts = array(), $content = null ) {

		$a = shortcode_atts( array(
			'address' => '',
			'subject' => null
		), $atts );

		$address = create_email_to_address( $a['address'], $a['subject'] );

// 	$link = "<a href=\"{$address}\">{$content}</a>";

// 	pretty_print($link);

// 	return $link;

		//return '<a href=mailto:"' . esc_attr($a['address'] . '?subject="' . $a['subject']) . '">' . $content . '</a>';

		return create_email_link( $a['address'], $a['subject'], $content );

	}
}

if ( ! function_exists( 'create_meeting_link' ) ) {
	function create_meeting_link( $slug ) {

		return '/meetings/?meeting=' . $slug;

	}
}

if ( ! function_exists( 'is_online' ) ) {

	function is_online( $types ) {

		return ( in_array( 'ONL', $types, false ) );

	}
}