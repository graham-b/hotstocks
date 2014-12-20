<?php
$OS = getenv('OS');
if (strpos($OS, 'Windows') === false) {
	echo "Error: this script can only be run on Windows\n";
	exit();
}
$USERPROFILE = getenv('USERPROFILE');
$largecap = 1.0; # billion
$minimum_volume = 1.00; # million
$minimum_price = 5.00; # dollars
$minimum_gap = 1.0; # percent
$gap_normalizer = 150;
$max_num_earnings = 9999; # for debugging
$allow_weekend_scans = false; # for debugging
$earningswhispers_url = 'http://earningswhispers.com/calendar.asp';
$tomorrow_earningswhispers_url = 'http://earningswhispers.com/calendar.asp?way=N&day=-1&s=n';
$google_finance_url = 'http://www.google.com/finance?q=';
$yahoourl = "http://query.yahooapis.com/v1/public/yql?q=";
$cachefile = getcwd() . "\\earnings_cache.txt";
$words_to_strip = array(	'.', ',', '-', ' INCORPORATED', ' INC', ' CORPORATION', ' CORP', ' LTD', ' LIMITED', ' PLC', ' (USA)', ' HOLDINGS', ' HOLDING', 'AND ', '&AMP; ', '& ');
$match_min_percent = 70.0;
$largecap_sheet_file = $USERPROFILE . '\Desktop\largecapearnings.html';

$symbols = array();
$qstrings = array();
$companies = array();
$opens = array();
$stocklist = array();
$stocklist_by_gap_amount = array();
$stocklist_by_gap_text = array();
$data = array();
$savedtime = '';
$output = '';
$innertxt = '';

$dow = date("l",time());
if ($dow == 'Sunday') {
	$date = date("Y/m/d",time() + (24 * 60 * 60));
} elseif ($dow == 'Saturday') {
	$date = date("Y/m/d",time() + (2 * 24 * 60 * 60));
} else {
	$date = date("Y/m/d",time());
}

function getVolume($page, $symbol, $recursing=false) {
	global $companies, $qstrings, $opens, $google_finance_url, $output, $minimum_price, $stocklist, $stocklist_by_gap_amount, $stocklist_by_gap_text, $largecap, $minimum_volume, $atc, $match_min_percent, $minimum_gap;
	$stock_good = false;
	$volumenum = 0;
	$symbol = trim($symbol);
	$url = $page . $qstrings[$symbol];
	$html = new simple_html_dom();
	$html->load_file($url);
	$title_arr = $html->find('title');
	$title = $title_arr[0]->innertext;
	if (strpos($title, '(ADR)') === false && strpos($title, ' ADR:') === false) {
		$company = trim($companies[$symbol]);
		$colon_pos = strpos($title, ':');
		if ($colon_pos === false) {
			echo "company: Colon not found in title tag: \"$title\" for symbol: \"$symbol\"\n";
			if (!$recursing) { # avoid infinite recursion
				$html->clear();
				unset($html);
				$qstrings[$symbol] = $symbol;
				return getVolume($page, $symbol, true);
			} else {
				echo "company not found using qstring: " . $qstrings[$symbol] . "\n";
				return false;
			}
		}
		if ($recursing) {
			echo "company found using qstring: " . $qstrings[$symbol] . "\n";
		}
		$title = substr($title, 0, $colon_pos);
		$company_stripped = strip_company($company);
		$title_stripped = strip_company($title);
		similar_text($company_stripped, $title_stripped, $percent);
		if ($company_stripped) {
			if (($percent >= $match_min_percent) || strpos($title_stripped, $company_stripped) !== false) {
				$tds = $html->find('td[data-snapfield=vol_and_avg]');
				if ($tds[0]->innertext != '') {
					$td = $tds[0]->next_sibling();
					$vol = $td->innertext;
					$vol = trim($vol);
					if (substr($vol, -1) == 'M') {
						$volumenum = substr(strrchr($vol, "/"), 1);
						$volumenum = substr($volumenum, 0, strrpos($volumenum, 'M'));
					} elseif (strpos($vol, 'M/')) {
						$volumenum = substr($vol, 0, strpos($vol, 'M'));
					} else {
						$volumenum = substr(strrchr($vol, "/"), 1);
						$volumenum = preg_replace('@\,@', '', $volumenum);
						$volumenum = preg_replace('@\.00@', '', $volumenum);
					}
					if ($volumenum >= $minimum_volume) {
						$vol = preg_replace('@\.00@', '', $vol);
						$divs = $html->find('div[id=price-panel]');
						$span = $divs[0]->first_child()->first_child()->first_child();
						$price = trim($span->innertext);
						if ($price >= $minimum_price) {

							$current_delta = trim($divs[0]->first_child()->first_child()->next_sibling()->first_child()->first_child()->innertext);
							$close_yesterday = $price - $current_delta;
							$open_yesterday = $opens[$symbol];
	echo "price:$price current_delta:$current_delta open_yesterday:$open_yesterday close_yesterday:$close_yesterday\n";
							if (preg_match('/^\d+\.\d+$/', $open_yesterday)) {
								$daily_gain = sprintf("%01.2f", $close_yesterday - $open_yesterday);
								if ($open_yesterday != 0) {
									$daily_gain_percent = sprintf("%01.2f", ($daily_gain * 100) / $open_yesterday);
								}
							}

							$div_pre_market = $divs[0]->first_child()->next_sibling();
							$div_pre_market_text = trim($div_pre_market->innertext);
	#echo "div_pre_market_text: $div_pre_market_text\n";

							if (strpos($div_pre_market_text, 'Pre-market') !== false || strpos($div_pre_market_text, 'After Hours') !== false) {

								$gap_text[$symbol] = $div_pre_market->first_child()->next_sibling()->next_sibling()->innertext;
								preg_match('/\((\-?\d+\.\d+)\%\)/', $gap_text[$symbol], $matches);
								$gap_amount[$symbol] = $matches[1];
								echo "gap_text is {$gap_text[$symbol]}\n";
							} else {
								echo "No Pre-market / After Hours\n";
								$tod_text = trim($div_pre_market->first_child()->innertext);
								if (strpos($tod_text, 'Delayed') !== false) {
									echo "$symbol is delayed. ";
								}
								if (strpos($tod_text, 'Close') !== false || strpos($tod_text, 'Real-time') !== false) {

									if (preg_match('/^[\-\+]?\d+\.\d+$/', $current_delta)) {
										$snap_panel = $html->find('div[class=snap-panel]');
										$open_today = trim($snap_panel[0]->first_child()->first_child()->next_sibling()->next_sibling()->first_child()->next_sibling()->innertext);
										echo "open_today from Google:$open_today\n";
										if (!preg_match('/^\d+\.\d+$/', $open_today)) {
											$open_today = getOpen($symbol);
											if (!preg_match('/^\d+\.\d+$/', $open_today)) {
												sleep(2);
												$open_today = getOpen($symbol);
											}
										}
										if (preg_match('/^\d+\.\d+$/', $open_today)) {
											if ($close_yesterday != 0) {
												$percent_gap = (($open_today - $close_yesterday) * 100) / $close_yesterday;
												$gap_amount[$symbol] = sprintf("%01.2f", $percent_gap);
												$gap_text[$symbol] = "({$gap_amount[$symbol]}%)";
												echo "calculated gap is {$gap_amount[$symbol]}%\n";
												echo "price $price current_delta $current_delta close_yesterday $close_yesterday open_today $open_today\n";
											} else {
												echo "Error: close_yesterday: $close_yesterday\n";
											}
										} else {
											echo "Error: open_today: $open_today\n";
										}
									} else {
										echo "Error: current_delta: $current_delta\n";
									}
								} else {
									echo "Error: tod_text: " . substr($tod_text, 0, 15) . "\n";
								}
							}
							if (isset($gap_amount[$symbol])) {
								if ($gap_amount[$symbol]>$minimum_gap || $gap_amount[$symbol]<(-$minimum_gap)) {
									if ($gap_amount[$symbol]>0) {
										$gap_bar_height = ($gap_amount[$symbol] * 10);
										$gap_bar = "<img height='$gap_bar_height' width='8' src='greenbar.gif'>";
									} else {
										$gap_bar_height = (-$gap_amount[$symbol] * 10);
										$gap_bar = "<img height='$gap_bar_height' width='8' src='redbar.gif'>";
									}
									if ($daily_gain_percent>0) {
										$daily_gain_height = ($daily_gain_percent * 10);
										$daily_gain_bar = "<img height='$daily_gain_height' width='8' src='greenbar.gif'>";
									} else {
										$daily_gain_height = (-$daily_gain_percent * 10);
										$daily_gain_bar = "<img height='$daily_gain_height' width='8' src='redbar.gif'>";
									}
									$tds = $html->find('td[data-snapfield=market_cap]');
									$td = $tds[0]->next_sibling();
									$mktcap = trim($td->innertext);
									$star = '';
									if (substr($mktcap, -1) == 'B') {
										$capnum = substr($mktcap, 0, -1);
										if ($capnum >= $largecap) {
											$stocklist[$symbol] = $symbol . $gap_text[$symbol];
											$stocklist_by_gap_amount[$symbol] = $gap_amount[$symbol];
											$stocklist_by_gap_text[$symbol] = $gap_text[$symbol];
											$star = '*';
											echo "$symbol is large/mid cap\n";
										}
									}
									$output .= "<TR><TD><A HREF='$google_finance_url" . $qstrings[$symbol] . "' TARGET='_blank'>$symbol</A>$star</TD><TD>$company</TD><TD  ALIGN=right>$price</TD><TD ALIGN=right>$vol</TD><TD ALIGN=right>$mktcap</TD><TD ALIGN=right VALIGN=bottom>$daily_gain ($daily_gain_percent%) $daily_gain_bar</TD><TD ALIGN=right VALIGN=bottom>" . $gap_amount[$symbol] . "% $gap_bar</TD></TR>\n";
									$stock_good = true;
									echo "$symbol is good.\n";
								} else {
									echo "Gap too small\n";
								}
							} else {
								echo "gap is undetermined\n";
							}
						} else {
							echo "$symbol price $price less than $minimum_price\n";
						}
					} else {
						echo "$symbol volume $vol less than 1M\n";
					}
				} else {
					echo "Error: $symbol innertext NULL\n";
				}
			} else {
				echo "Error: companies do not match: $company_stripped, $title_stripped\n";
			}
		} else {
			echo "Error: company_stripped is null\n";
		}
	} else {
		echo "$symbol is ADR\n";
	}
	$html->clear();
	unset($html);
	return $stock_good;
}

function getEarnings($page) {
	global $symbols, $companies, $qstrings, $atc;
#	echo "page:$page\n";
	$html = new simple_html_dom();
	if ($atc) {
		$html->load($page);
	} else {
		$html->load_file($page);
	}
	$tds = $html->find('td[width=310]');
	foreach($tds as $ticker) {
		if ($ticker->getAttribute('valign')  != 'middle') {
			$symbol = $ticker->children(0)->innertext;
			if (!in_array($symbol, $symbols)) {
				$symbols[] = $symbol;
				$company = $ticker->innertext;
				$company = preg_replace('/ \(.+$/', '', $company);
				$companies[$symbol] = $company;
				$qstrings[$symbol] = urlencode(trim($company));
				if ($atc) {
					echo "symbol:$symbol	company:$company qstrings:{$qstrings[$symbol]}\n";
				}
			} else {
				echo "Duplicate symbol: $symbol\n";
			}
		}
	}
	$html->clear();
	unset($html);
}

function strip_company($company) {
	global $words_to_strip;
	$company = strtoupper($company);
	foreach ($words_to_strip as $tostrip) {
		$company = str_replace(strtoupper($tostrip), '', $company);
	}
	$company = preg_replace('/CO$/', '', $company);
	$company = preg_replace('/COMPANY$/', '', $company);
	$company = preg_replace('/&#39;/', '\'', $company);
	$company = preg_replace('/&#146;/', '\'', $company);
	return $company;
}

function getOpen($symbol) {
	global $yahoourl;
	echo "getting open for $symbol from Yahoo\n";
	$parms = urlencode("select * from csv where url='http://download.finance.yahoo.com/d/quotes.csv?s=$symbol&f=o&e=.csv' and columns='o'");

	$yql_query_url = $yahoourl . $parms;
	$yql_query_url .= "&format=json";

	$session = curl_init($yql_query_url);
	curl_setopt($session, CURLOPT_RETURNTRANSFER,true);
	$json = curl_exec($session);

	$phpObj =  json_decode($json);
	if(!is_null($phpObj->query->results)){
		$open = $phpObj->query->results->row->o;
		if (preg_match('/^\d+\.\d+$/', $open)) {
			return $open;
		}
	}
	echo "Error: Yahoo failed to get open:$open for symbol:$symbol\n";
	return '0';
}

?>