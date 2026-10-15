<?php

namespace App\Libraries;

/**
 * The screening questions, as templates that draw fresh details for every
 * applicant (see App\Libraries\Screening).
 *
 * Every applicant answers one question of each kind:
 *
 * - comprehension: a short workplace passage (a notice, an email, a policy)
 *   and a question whose answer is in the text. Names, times, amounts and
 *   the order of steps change per draw, so the passage cannot be searched.
 * - language: spelling and grammar, vocabulary, or workplace writing, drawn
 *   from a pool per level with names filled in and the options shuffled.
 * - numbers: everyday sums (change, shift times, pay, stock, quotes), never
 *   a formula to remember. Fresh numbers per draw.
 *
 * Each generator returns the question, an optional passage, four options
 * (the right one plus three built from the usual mistakes) and `params`, the
 * details it drew, which ScreeningBankTest uses to work the answer out again.
 *
 * Ids are stored in results, so an id is never reused for a different
 * template. Customer copy: no dashes and no hyphenated words. The minus sign
 * (−) is the maths sign, not a dash.
 */
class ScreeningBank
{
    public const KINDS = ['comprehension', 'language', 'numbers'];

    /** @var array<string,array<string,list<string>>> level => kind => template ids */
    public const LEVELS = [
        'basic' => [
            'comprehension' => ['bc1', 'bc2', 'bc3', 'bc4'],
            'language'      => ['bl1', 'bl2', 'bl3'],
            'numbers'       => ['bn1', 'bn2', 'bn3', 'bn4', 'bn5'],
        ],
        'intermediate' => [
            'comprehension' => ['ic1', 'ic2', 'ic3', 'ic4'],
            'language'      => ['il1', 'il2', 'il3'],
            'numbers'       => ['in1', 'in2', 'in3', 'in4', 'in5'],
        ],
        'advanced' => [
            'comprehension' => ['ac1', 'ac2', 'ac3', 'ac4'],
            'language'      => ['al1', 'al2', 'al3'],
            'numbers'       => ['an1', 'an2', 'an3', 'an4', 'an5'],
        ],
    ];

    private const NAMES = ['Thabo', 'Ayanda', 'Pieter', 'Naledi', 'Zanele', 'Johan', 'Priya', 'Sipho', 'Lerato', 'Fatima', 'Kagiso', 'Anika'];

    private const CUSTOMERS = ['Mr Dlamini', 'Ms Naidoo', 'Mrs van Wyk', 'Mr Mokoena', 'Ms Pillay', 'Mr Botha', 'Mrs Khumalo'];

    private const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    private const ITEMS = ['kettle', 'heater', 'printer', 'microwave', 'vacuum cleaner', 'office chair'];

    // ------------------------------------------------------ language pools

    /**
     * Spelling and grammar: the first sentence is right, the other three
     * each carry one mistake.
     */
    private const GRAMMAR = [
        'basic' => [
            ['{name} and I will open the shop tomorrow.', '{name} and me will open the shop tomorrow.', 'Me and {name} will open the shop tomorrow.', '{name} and I will opened the shop tomorrow.'],
            ['Their order is ready to collect.', 'There order is ready to collect.', 'They\'re order is ready to collect.', 'Thier order is ready to collect.'],
            ['We received your message yesterday.', 'We recieved your message yesterday.', 'We received your message yesturday.', 'We receive your message yesterday.'],
            ['Please bring your ID to the interview.', 'Please brings your ID to the interview.', 'Please bring you ID to the interview.', 'Please bring your ID too the interview.'],
            ['The delivery was late because of the rain.', 'The delivery was late becuase of the rain.', 'The delivery were late because of the rain.', 'The delivery was late because off the rain.'],
            ['{name} has finished the stock count.', '{name} have finished the stock count.', '{name} has finish the stock count.', '{name} has finished the stock cont.'],
            ['It\'s important to arrive on time.', 'Its important to arrive on time.', 'It\'s important to arrive on tyme.', 'Its\' important to arrive on time.'],
            ['Your account has been updated.', 'You\'re account has been updated.', 'Your account has been updatted.', 'Your account have been updated.'],
        ],
        'intermediate' => [
            ['Fewer customers came in this week.', 'Less customers came in this week.', 'Fewer customer came in this week.', 'Fewer customers comed in this week.'],
            ['The new stock will affect our prices.', 'The new stock will effect our prices.', 'The new stock will affects our prices.', 'The new stock will afect our prices.'],
            ['Each of the drivers has a logbook.', 'Each of the drivers have a logbook.', 'Each of the driver has a logbook.', 'Each off the drivers has a logbook.'],
            ['Please send the invoice to {name} or me.', 'Please send the invoice to {name} or I.', 'Please sent the invoice to {name} or me.', 'Please send the invoice too {name} or me.'],
            ['We have already paid the supplier.', 'We have all ready paid the supplier.', 'We have already payed the supplier.', 'We has already paid the supplier.'],
            ['I would have called if I had known.', 'I would of called if I had known.', 'I would have call if I had known.', 'I would have called if I had knew.'],
            ['The customer\'s complaint was resolved quickly.', 'The customers complaint was resolved quickly.', 'The customer\'s complaint was resolve quickly.', 'The customer\'s complant was resolved quickly.'],
            ['Separate the damaged boxes from the rest.', 'Seperate the damaged boxes from the rest.', 'Separate the damaged boxs from the rest.', 'Separate the damaged boxes then the rest.'],
        ],
        'advanced' => [
            ['The report, which was due on Friday, is now late.', 'The report which was due on Friday, is now late.', 'The report, which was due on Friday is now late.', 'The report, which were due on Friday, is now late.'],
            ['Having checked the stock, {name} placed the order.', 'Having checked the stock, the order was placed by {name}.', 'Having check the stock, {name} placed the order.', 'Having checked the stock {name}, placed the order.'],
            ['Whose turn is it to lock up?', 'Who\'s turn is it to lock up?', 'Whos turn is it to lock up?', 'Whose turn is it too lock up?'],
            ['We need three things: gloves, masks and boots.', 'We need three things; gloves, masks and boots.', 'We need, three things: gloves, masks and boots.', 'We need three things: gloves, mask\'s and boots.'],
            ['Between you and me, the plan needs work.', 'Between you and I, the plan needs work.', 'Between you and me the plan, needs work.', 'Between you and myself, the plan needs work.'],
            ['If I were the manager, I would change the roster.', 'If I was the manager, I would changed the roster.', 'If I were the manager, I will change the roster.', 'If I were the manager I would change, the roster.'],
            ['The committee has made its decision.', 'The committee has made it\'s decision.', 'The committee has maked its decision.', 'The committee has made its decission.'],
            ['Fill in the form, sign it and return it by Friday.', 'Fill in the form, sign it and returning it by Friday.', 'Fill in the form, signing it and return it by Friday.', 'Fill in the form sign it, and return it by Friday.'],
        ],
    ];

    /** Vocabulary: [sentence, word, right meaning, three wrong ones]. */
    private const VOCABULARY = [
        'basic' => [
            ['Please arrive promptly at 08:00.', 'promptly', 'on time', 'slowly', 'quietly', 'late'],
            ['The manager will assist you with the forms.', 'assist', 'help', 'stop', 'pay', 'test'],
            ['The customer was satisfied with the service.', 'satisfied', 'happy', 'angry', 'bored', 'unsure'],
            ['Please inform your team about the change.', 'inform', 'tell', 'hide from', 'thank', 'invite'],
            ['This order is urgent.', 'urgent', 'needed quickly', 'very large', 'cheap', 'cancelled'],
            ['We need to purchase more paper.', 'purchase', 'buy', 'sell', 'borrow', 'print'],
            ['The room must be vacant by noon.', 'vacant', 'empty', 'dirty', 'locked', 'booked'],
            ['{name} was reluctant to work late.', 'reluctant', 'unwilling', 'happy', 'quick', 'able'],
        ],
        'intermediate' => [
            ['The supplier will reimburse the cost.', 'reimburse', 'pay back', 'charge more for', 'increase', 'check'],
            ['Please verify the customer\'s address.', 'verify', 'check that it is correct', 'change', 'delete', 'copy'],
            ['The meeting has been postponed.', 'postponed', 'moved to a later time', 'cancelled', 'brought forward', 'made shorter'],
            ['This offer is valid until Friday.', 'valid', 'able to be used', 'free', 'new', 'hidden'],
            ['Staff must adhere to the dress code.', 'adhere to', 'follow', 'change', 'ignore', 'discuss'],
            ['The figures in the report are approximate.', 'approximate', 'close but not exact', 'exact', 'missing', 'wrong'],
            ['{name} gave constructive feedback.', 'constructive', 'helpful', 'rude', 'short', 'late'],
            ['The training is mandatory for all drivers.', 'mandatory', 'required', 'optional', 'new', 'unclear'],
        ],
        'advanced' => [
            ['The client\'s request was ambiguous.', 'ambiguous', 'open to more than one meaning', 'very clear', 'urgent', 'rude'],
            ['We need to prioritise the urgent orders.', 'prioritise', 'deal with first', 'cancel', 'count', 'delay'],
            ['The two reports are consistent.', 'consistent', 'in agreement with each other', 'in conflict with each other', 'very long', 'missing pages'],
            ['Sales declined in the third quarter.', 'declined', 'went down', 'went up', 'stayed the same', 'were shared'],
            ['The manager was candid about the problems.', 'candid', 'open and honest', 'angry', 'unaware', 'secretive'],
            ['These extra costs are negligible.', 'negligible', 'so small they hardly matter', 'very high', 'unexpected', 'hidden'],
            ['Please refrain from using phones on the shop floor.', 'refrain from', 'avoid', 'start', 'enjoy', 'report'],
            ['The new system will streamline the process.', 'streamline', 'make simpler and quicker', 'slow down', 'make more expensive', 'stop'],
        ],
    ];

    /** Workplace writing: [situation, the best reply, three weaker ones]. */
    private const WRITING = [
        'basic' => [
            ['A customer asks if their order is ready, but it will only be ready tomorrow. Which reply is best?',
                'Hi {cust}, your order will be ready tomorrow. We will send you a message as soon as it is.',
                'Not ready.', 'It is not ready yet, come back sometime.', 'Why are you asking again? It will be ready when it is ready.'],
            ['You will be late for your shift because your taxi broke down. Which message to your manager is best?',
                'Hi {mgr}, my taxi has broken down, so I will be about {n} minutes late. I am sorry and will be there as soon as I can.',
                'Running late.', 'Taxi broke. Not my fault.', 'I might come in later or maybe not today.'],
            ['A customer thanks you for your help. Which reply is best?',
                'You are welcome, {cust}. Please let us know if you need anything else.',
                'Ok.', 'Yes.', 'No problem, but next time please read the instructions.'],
            ['You need a day off next {day}. Which request to your manager is best?',
                'Hi {mgr}, may I please take leave next {day}? I have a family matter, and {name} has agreed to cover my shift.',
                'I am not coming in next {day}.', 'Need {day} off.', 'Can I have next {day} off, or else I will just call in sick?'],
        ],
        'intermediate' => [
            ['A customer complains that their delivery is two days late. Which reply is best?',
                'I am sorry your delivery is late, {cust}. I have checked with our courier, and it will reach you tomorrow before 12:00.',
                'Deliveries are sometimes late. There is nothing we can do.', 'Sorry!!! Courier issues lol.', 'Please contact the courier yourself.'],
            ['You must remind the team that the store opens at {time} on Saturday. Which message is clearest?',
                'Reminder: on Saturday the store opens at {time}. Please be at your stations by then.',
                'Hi all, about Saturday, you know what I mean, be early.', 'Saturday times might be different, check with someone.', 'The store opens on Saturday.'],
            ['A customer asks for a discount you are not allowed to give. Which reply is best?',
                'Thank you for asking, {cust}. I am not able to offer a discount on this item, but I can tell you about our specials this week.',
                'No.', 'That is not my problem.', 'I will give you one if you do not tell my manager.'],
            ['You made a mistake on a customer\'s invoice. Which email is best?',
                'Dear {cust}, I made an error on your invoice. Please find the corrected invoice attached. I apologise for the trouble.',
                'Invoice was wrong. New one attached.', 'Our system messed up your invoice, not us.', 'Ignore the last invoice.'],
        ],
        'advanced' => [
            ['An angry customer emails in capital letters about a faulty {item}. Which reply is best?',
                'Dear {cust}, I am sorry the {item} is not working. I have arranged a replacement: our courier will deliver it on {day} and collect the faulty one at the same time.',
                'PLEASE DO NOT SHOUT AT US.', 'Faults happen. Send it back and we will look at it when we can.', 'Dear {cust}, the {item} was working when it left us, so the fault must be yours.'],
            ['You need to tell a client that a project will be one week late. Which message is best?',
                'Dear {cust}, the project will be one week later than planned because a supplier delivered late. The new date is {date}, and we will deliver the first part on the original date.',
                'We are running a bit late but should be fine.', 'Sorry, the supplier let us down, so it is not our fault.', 'The project is late. New date to follow.'],
            ['A colleague keeps arriving late, and you have to cover their counter. Which message to them is best?',
                'Hi {name}, can we chat? When you arrive late, I have to cover your counter and I fall behind. Could we find a way to fix this together?',
                'Everyone has noticed that you are always late.', 'I am reporting you to the manager.', 'Nice of you to finally show up.'],
            ['A supplier sends an invoice that is higher than their quote. Which reply is best?',
                'Hi {name}, the invoice is R{diff} more than your quote of R{quote}. Please send a corrected invoice, or explain the extra cost, before we approve it.',
                'Fine, we will pay it.', 'This is a scam.', 'Wrong invoice.'],
        ],
    ];

    /** @return list<string> every template id at $level */
    public function ids(string $level): array
    {
        return array_merge(...array_values(self::LEVELS[$level] ?? [[]]));
    }

    /** @return list<string> */
    public function idsOf(string $level, string $kind): array
    {
        return self::LEVELS[$level][$kind] ?? [];
    }

    /**
     * One freshly drawn question.
     *
     * @return array{id:string,passage:string|null,prompt:string,options:list<string>,answer:int,params:array<string,mixed>}
     */
    public function generate(string $id): array
    {
        $method = 'q' . $id;
        if (! method_exists($this, $method)) {
            throw new \InvalidArgumentException('No screening template ' . $id);
        }

        // A draw whose mistakes collide with the answer, or with each other,
        // gives fewer than four options; draw again.
        for ($try = 0; $try < 50; $try++) {
            $q     = $this->{$method}();
            $built = $this->options($q['correct'], $q['wrong']);
            if ($built !== null) {
                return [
                    'id'      => $id,
                    'passage' => $q['passage'] ?? null,
                    'prompt'  => $q['prompt'],
                    'options' => $built[0],
                    'answer'  => $built[1],
                    'params'  => $q['params'] ?? [],
                ];
            }
        }

        throw new \RuntimeException('Screening template ' . $id . ' could not build four options');
    }

    // ------------------------------------------- basic: comprehension

    /** Trading hours notice: read off one time. */
    private function qbc1(): array
    {
        $shop   = $this->pick(['Sunrise Hardware', 'Kasi Kitchen', 'Green Valley Pharmacy', 'Lekker Bakes', 'Corner Cellular']);
        $open   = random_int(7, 9) * 60 + $this->pick([0, 30]);
        $close  = random_int(16, 18) * 60 + $this->pick([0, 30]);
        $sopen  = random_int(8, 9) * 60 + $this->pick([0, 30]);
        $sclose = random_int(12, 14) * 60 + $this->pick([0, 30]);
        $times  = ['weekday opening' => $open, 'weekday closing' => $close, 'Saturday opening' => $sopen, 'Saturday closing' => $sclose];
        $ask    = $this->pick(array_keys($times));

        $question = [
            'weekday opening'  => 'What time does the shop open on a weekday?',
            'weekday closing'  => 'What time does the shop close on a weekday?',
            'Saturday opening' => 'What time does the shop open on a Saturday?',
            'Saturday closing' => 'What time does the shop close on a Saturday?',
        ][$ask];

        return [
            'passage' => "{$shop} has new trading hours. From " . $this->pick(self::WEEKDAYS) . ', we open at ' . $this->time($open)
                . ' and close at ' . $this->time($close) . ' on weekdays. On Saturdays we open at ' . $this->time($sopen)
                . ' and close at ' . $this->time($sclose) . '. We are closed on Sundays and public holidays.',
            'prompt'  => $question,
            'correct' => $this->time($times[$ask]),
            'wrong'   => array_values(array_map(fn ($t) => $this->time($t), array_diff_key($times, [$ask => true]))),
            'params'  => ['ask' => $ask, 'times' => array_map(fn ($t) => $this->time($t), $times)],
        ];
    }

    /** A customer moves a booking: which day, or when to call. */
    private function qbc2(): array
    {
        $name    = $this->pick(self::NAMES);
        $service = $this->pick(['haircut', 'car service', 'eye test', 'tyre fitting', 'nail appointment']);
        [$day, $day2, $other1, $other2] = $this->several(self::WEEKDAYS, 4);
        $time  = $this->time(random_int(8, 15) * 60 + $this->pick([0, 30]));
        $after = $this->time(random_int(16, 18) * 60);
        $byDay = random_int(0, 1) === 1;

        return [
            'passage' => "Hi, this is {$name}. I have a {$service} booked for {$day} at {$time}, but something has come up. "
                . "Please move it to {$day2} at the same time. You can call me on my cell after {$after} to confirm.",
            'prompt'  => $byDay ? "Which day does {$name} want the booking moved to?" : "When does {$name} want you to call?",
            'correct' => $byDay ? $day2 : "After {$after}",
            'wrong'   => $byDay ? [$day, $other1, $other2] : ["Before {$after}", "At {$time}", "On {$day} morning"],
            'params'  => ['ask' => $byDay ? 'day' : 'call', 'day' => $day2, 'after' => $after],
        ];
    }

    /** A delivery note: how many boxes were put away. */
    private function qbc3(): array
    {
        $n     = random_int(12, 40);
        $d     = random_int(2, 9);
        $item  = $this->pick(['printing paper', 'cooking oil', 'tinned tomatoes', 'cleaning liquid', 'school shoes']);
        $place = $this->pick(['storeroom', 'back office', 'cold room', 'loading bay']);

        return [
            'passage' => 'On ' . $this->pick(self::WEEKDAYS) . ", {$n} boxes of {$item} were delivered. {$d} of the boxes were damaged, "
                . "so the driver took them back. The rest were packed away in the {$place}.",
            'prompt'  => "How many boxes were packed away in the {$place}?",
            'correct' => (string) ($n - $d),
            'wrong'   => [(string) $n, (string) $d, (string) ($n + $d), (string) ($n - $d + 1)],
            'params'  => ['delivered' => $n, 'damaged' => $d],
        ];
    }

    /** Closing steps in a random order: which comes first, second, and so on. */
    private function qbc4(): array
    {
        $name  = $this->pick(self::NAMES);
        $steps = $this->several([
            'Switch off the ' . $this->pick(['lights', 'fryer', 'air conditioner', 'coffee machine']),
            'Lock the ' . $this->pick(['back door', 'storeroom', 'safe', 'front gate']),
            'Sign the register at the front desk',
            "Hand the keys to {$name}",
        ], 4);
        $lower = static fn (string $s) => lcfirst($s);

        $passage = 'At the end of every shift, first ' . $lower($steps[0]) . '. Then ' . $lower($steps[1])
            . '. After that, ' . $lower($steps[2]) . '. Last of all, ' . $lower($steps[3]) . '.';
        $pos   = random_int(0, 3);
        $label = ['first', 'second', 'third', 'last'][$pos];

        return [
            'passage' => $passage,
            'prompt'  => "What is the {$label} thing to do at the end of a shift?",
            'correct' => $steps[$pos],
            'wrong'   => array_values(array_diff($steps, [$steps[$pos]])),
            'params'  => ['steps' => $steps, 'pos' => $pos],
        ];
    }

    // ------------------------------------------------ basic: language

    private function qbl1(): array
    {
        return $this->grammar('basic');
    }

    private function qbl2(): array
    {
        return $this->vocabulary('basic');
    }

    private function qbl3(): array
    {
        return $this->writing('basic');
    }

    // ------------------------------------------------- basic: numbers

    private function qbn1(): array
    {
        $item  = $this->pick(['A shirt', 'A kettle', 'A jacket', 'A pair of boots', 'A school bag']);
        $price = random_int(2, 40) * 100;
        $pct   = $this->pick([10, 15, 20, 25, 30, 40, 50]);
        $off   = intdiv($price * $pct, 100);

        return [
            'prompt'  => "{$item} costs " . $this->rand($price) . " and is on sale at {$pct}% off. What is the sale price?",
            'correct' => $this->rand($price - $off),
            'wrong'   => [$this->rand($off), $this->rand($price - $pct), $this->rand($price + $off)],
            'params'  => ['price' => $price, 'pct' => $pct],
        ];
    }

    private function qbn2(): array
    {
        $start = random_int(6, 10) * 60 + $this->pick([0, 15, 30, 45]);
        $hours = random_int(7, 9);
        $mins  = $this->pick([15, 30, 45]);
        $end   = $start + $hours * 60 + $mins;

        return [
            'prompt'  => 'A shift starts at ' . $this->time($start) . " and lasts {$hours} hours and {$mins} minutes. What time does it end?",
            'correct' => $this->time($end),
            'wrong'   => [$this->time($end - 60), $this->time($end + 60), $this->time($end - 30), $this->time($end + 30)],
            'params'  => ['start' => $this->time($start), 'minutes' => $hours * 60 + $mins],
        ];
    }

    private function qbn3(): array
    {
        $paid  = $this->pick([200, 300, 500, 1000]);
        $k     = random_int(2, 5);
        $each  = random_int(15, intdiv($paid - 10, $k));
        $total = $k * $each;

        return [
            'prompt'  => 'You pay ' . $this->rand($paid) . " for {$k} items that cost " . $this->rand($each) . ' each. How much change do you get?',
            'correct' => $this->rand($paid - $total),
            'wrong'   => [$this->rand($paid - $each), $this->rand($paid - $total + 10), $this->rand($paid - $total - 10), $this->rand($total)],
            'params'  => ['paid' => $paid, 'items' => $k, 'each' => $each],
        ];
    }

    private function qbn4(): array
    {
        $thing = $this->pick(['rolls', 'bars of soap', 'bottles', 'packets']);
        $a     = random_int(2, 6);
        do {
            $b = random_int(2, 9);
        } while ($b === $a);
        $ua    = random_int(8, 30);
        $ub    = $ua + $this->pick([-4, -3, -2, -1, 1, 2, 3, 4]);
        $right = $ua < $ub ? 'Pack A' : 'Pack B';

        return [
            'prompt'  => "Pack A has {$a} {$thing} for " . $this->rand($a * $ua) . ". Pack B has {$b} {$thing} for " . $this->rand($b * $ub) . '. Which is cheaper per item?',
            'correct' => $right,
            'wrong'   => array_values(array_diff(['Pack A', 'Pack B', 'They cost the same per item', 'You cannot tell'], [$right])),
            'params'  => ['a' => $a, 'pa' => $a * $ua, 'b' => $b, 'pb' => $b * $ub],
        ];
    }

    private function qbn5(): array
    {
        $what  = $this->pick(['A bus', 'A train', 'A taxi']);
        $leave = random_int(6, 15) * 60 + random_int(0, 11) * 5;
        $trip  = random_int(7, 34) * 5;

        return [
            'prompt'  => "{$what} leaves at " . $this->time($leave) . ' and arrives at ' . $this->time($leave + $trip) . '. How long is the trip?',
            'correct' => $this->duration($trip),
            'wrong'   => [$this->duration($trip + 20), $this->duration(max(5, $trip - 20)), $this->duration($trip + 60), $this->duration($trip + 40)],
            'params'  => ['leave' => $this->time($leave), 'minutes' => $trip],
        ];
    }

    // ------------------------------------ intermediate: comprehension

    /** A manager's email about a moved stock count: why, where or when. */
    private function qic1(): array
    {
        $name = $this->pick(self::NAMES);
        [$day, $day2] = $this->several(self::WEEKDAYS, 2);
        $reasons = [
            'because the delivery is late'                 => 'The delivery is late',
            'because the cold room is being repaired'      => 'The cold room is being repaired',
            'because head office is visiting on ' . $day   => 'Head office is visiting',
            'because the scanners are being serviced'      => 'The scanners are being serviced',
        ];
        $reason = $this->pick(array_keys($reasons));
        [$area, $area2, $area3, $area4] = $this->several(['front shop', 'storeroom', 'loading bay', 'back office'], 4);
        $mins  = $this->pick([15, 20, 30]);
        $mins2 = $mins === 30 ? 15 : 30;
        $ask   = $this->pick(['why', 'where', 'when']);

        $q = match ($ask) {
            'why'   => ['Why has the stock count been moved?', $reasons[$reason], array_values(array_diff($reasons, [$reasons[$reason]]))],
            'where' => ["Where will {$name} lead the count?", 'The ' . $area, ['The ' . $area2, 'The ' . $area3, 'The ' . $area4]],
            'when'  => ["What time should staff arrive on {$day2}?", "{$mins} minutes before their normal start time",
                ['At their normal start time', "{$mins} minutes after their normal start time", "{$mins2} minutes before their normal start time"]],
        };

        return [
            'passage' => "Team, the stock count moves from {$day} to {$day2} this week {$reason}. Please arrive {$mins} minutes before "
                . "your normal start time. {$name} will lead the count in the {$area}; everyone else starts in the {$area2}.",
            'prompt'  => $q[0],
            'correct' => $q[1],
            'wrong'   => $q[2],
            'params'  => ['ask' => $ask, 'reason' => $reasons[$reason], 'area' => $area, 'mins' => $mins],
        ];
    }

    /** A returns policy applied to one customer. */
    private function qic2(): array
    {
        $n    = $this->pick([7, 14, 30]);
        $item = $this->pick(self::ITEMS);
        $k    = random_int(1, $n - 1);
        $late = $n + random_int(2, 10);
        $case = $this->pick(['refund', 'exchange', 'sale', 'late']);

        $situation = match ($case) {
            'refund'   => "A customer bought a {$item} {$k} days ago. It was not on sale, and they have the till slip.",
            'exchange' => "A customer bought a {$item} {$k} days ago. It was not on sale, but they have lost the till slip.",
            'sale'     => "A customer bought a {$item} on sale {$k} days ago. It works perfectly, and they have the till slip.",
            'late'     => "A customer bought a {$item} {$late} days ago. It was not on sale, and they have the till slip.",
        };
        $answers = [
            'refund'   => 'A full refund',
            'exchange' => 'An exchange, but not a refund',
            'sale'     => 'Nothing, because sale items are only taken back when faulty',
            'late'     => "Nothing, because it is more than {$n} days",
        ];

        return [
            'passage' => "Returns policy: all returns and exchanges must be made within {$n} days. With a till slip, customers get a full refund. "
                . 'Without a till slip, items can be exchanged but not refunded. Sale items are only taken back if they are faulty.',
            'prompt'  => $situation . ' What can they get?',
            'correct' => $answers[$case],
            'wrong'   => array_values(array_diff($answers, [$answers[$case]])),
            'params'  => ['case' => $case],
        ];
    }

    /** A roster: who works a given slot, or nobody. */
    private function qic3(): array
    {
        [$a, $b, $c] = $this->several(self::NAMES, 3);
        [$d1, $d2, $d3, $free] = $this->several(self::WEEKDAYS, 4);
        $busy = array_values(array_diff(self::WEEKDAYS, [$d3]));

        $slots = [
            "on {$d1} morning"                         => $a,
            "on {$d3} afternoon"                       => $c,
            'on ' . $this->pick($busy) . ' afternoon'  => $b,
            'on Saturday morning'                      => $c,
            "on {$free} morning"                       => 'Nobody is listed',
        ];
        $slot = $this->pick(array_keys($slots));

        return [
            'passage' => "{$a} works mornings on {$d1} and {$d2}. {$b} works afternoons every weekday except {$d3}. "
                . "{$c} covers {$d3} afternoon and Saturday morning.",
            'prompt'  => "Who works {$slot}?",
            'correct' => $slots[$slot],
            'wrong'   => array_values(array_diff([$a, $b, $c, 'Nobody is listed'], [$slots[$slot]])),
            'params'  => ['slot' => $slot, 'names' => [$a, $b, $c], 'days' => [$d1, $d2, $d3, $free]],
        ];
    }

    /** A warning light procedure with the actions shuffled across colours. */
    private function qic4(): array
    {
        $name    = $this->pick(self::NAMES);
        $machine = $this->pick(['generator', 'water pump', 'cold room unit', 'compressor']);
        $actions = $this->several([
            "Switch it off and call {$name}",
            'Write it in the logbook and check again in an hour',
            'Carry on as normal',
        ], 3);
        $colours = ['red', 'orange', 'green'];
        $pick    = random_int(0, 2);
        $article = static fn (string $c) => ($c === 'orange' ? 'an ' : 'a ') . $c;

        $passage = "Check the {$machine} at the start of every shift. ";
        foreach ($colours as $i => $colour) {
            $passage .= 'If it shows ' . $article($colour) . ' light, ' . lcfirst($actions[$i]) . '. ';
        }

        return [
            'passage' => trim($passage),
            'prompt'  => "The {$machine} shows " . $article($colours[$pick]) . ' light. What should you do?',
            'correct' => $actions[$pick],
            'wrong'   => array_merge(array_values(array_diff($actions, [$actions[$pick]])), ["Call {$name} but leave it running"]),
            'params'  => ['actions' => $actions, 'colour' => $colours[$pick]],
        ];
    }

    // ---------------------------------------- intermediate: language

    private function qil1(): array
    {
        return $this->grammar('intermediate');
    }

    private function qil2(): array
    {
        return $this->vocabulary('intermediate');
    }

    private function qil3(): array
    {
        return $this->writing('intermediate');
    }

    // ----------------------------------------- intermediate: numbers

    /** A week's pay with overtime at one and a half times. */
    private function qin1(): array
    {
        $name  = $this->pick(self::NAMES);
        $base  = $this->pick([40, 45]);
        $hours = $base + random_int(2, 8);
        $rate  = 2 * random_int(15, 60);
        $pay   = $base * $rate + intdiv(($hours - $base) * $rate * 3, 2);

        return [
            'prompt'  => "{$name} worked {$hours} hours this week at " . $this->rand($rate) . " an hour. Hours over {$base} are paid at one and a half times the normal rate. How much does {$name} earn this week?",
            'correct' => $this->rand($pay),
            'wrong'   => [$this->rand($hours * $rate), $this->rand($base * $rate), $this->rand(intdiv($hours * $rate * 3, 2)), $this->rand($pay + $rate)],
            'params'  => ['hours' => $hours, 'base' => $base, 'rate' => $rate],
        ];
    }

    /** A stock count after deliveries, sales and breakages. */
    private function qin2(): array
    {
        $item     = $this->pick(['chairs', 'cases of water', 'bags of cement', 'phone covers']);
        $start    = random_int(20, 80);
        $received = random_int(10, 50);
        $damaged  = random_int(1, 6);
        $sold     = random_int(10, $start + $received - $damaged - 1);
        $left     = $start + $received - $sold - $damaged;

        return [
            'prompt'  => "A shop had {$start} {$item} in stock. It received {$received} more, sold {$sold}, and threw away {$damaged} that were damaged. How many are left?",
            'correct' => (string) $left,
            'wrong'   => [(string) ($left + $damaged), (string) ($left + 2 * $damaged), (string) ($start - $sold - $damaged), (string) ($left - 1)],
            'params'  => ['start' => $start, 'received' => $received, 'sold' => $sold, 'damaged' => $damaged],
        ];
    }

    private function qin3(): array
    {
        do {
            $v = $this->pick([60, 80, 90, 100, 120]);
            $m = $this->pick([15, 20, 30, 40, 45, 90]);
        } while (($v * $m) % 60 !== 0);
        $t = random_int(2, 5);
        $d = intdiv($v * $m, 60);

        return [
            'prompt'  => 'A delivery van travels ' . ($v * $t) . " km in {$t} hours. At the same speed, how far does it travel in {$m} minutes?",
            'correct' => $d . ' km',
            'wrong'   => [(int) round($v * $m / 100) . ' km', ($d * 2) . ' km', ($d + 10) . ' km', $v . ' km'],
            'params'  => ['distance' => $v * $t, 'hours' => $t, 'minutes' => $m],
        ];
    }

    /** Scale a recipe or order to a new number of people. */
    private function qin4(): array
    {
        $per  = random_int(8, 24) * 5;
        $p    = $this->pick([4, 5, 6, 8]);
        do {
            $q = random_int(3, 20);
        } while ($q === $p);
        $food = $this->pick(['rice', 'pap', 'flour', 'potatoes']);

        return [
            'prompt'  => "A recipe for {$p} people needs " . $this->num($per * $p) . " g of {$food}. How much {$food} is needed for {$q} people?",
            'correct' => $this->num($per * $q) . ' g',
            'wrong'   => [$this->num($per * $p + ($q - $p) * 10) . ' g', $this->num($per * ($q + 1)) . ' g', $this->num($per * $p * 2) . ' g', $this->num($per * ($q - 1)) . ' g'],
            'params'  => ['people' => $p, 'grams' => $per * $p, 'for' => $q],
        ];
    }

    private function qin5(): array
    {
        $name = $this->pick(self::NAMES);
        do {
            $avg  = random_int(10, 60);
            $four = [];
            for ($i = 0; $i < 4; $i++) {
                $four[] = $avg + random_int(-10, 10);
            }
            $fifth = 5 * $avg - array_sum($four);
        } while ($fifth <= 0);

        return [
            'prompt'  => "{$name} must average {$avg} sales a day over 5 days. The first four days were {$four[0]}, {$four[1]}, {$four[2]} and {$four[3]}. How many sales are needed on day 5?",
            'correct' => (string) $fifth,
            'wrong'   => [(string) $avg, (string) ($fifth + 5), (string) ($fifth - 5), (string) intdiv(array_sum($four), 4)],
            'params'  => ['average' => $avg, 'four' => $four],
        ];
    }

    // ---------------------------------------- advanced: comprehension

    /** A complaint email: what does the customer actually want? */
    private function qac1(): array
    {
        $name = $this->pick(self::CUSTOMERS);
        $item = $this->pick(self::ITEMS);
        $fee  = random_int(6, 20) * 10;
        $days = random_int(2, 6);
        $want = $this->pick(['fee', 'replacement', 'date']);

        $passage = match ($want) {
            'fee' => "I ordered a {$item} and paid R{$fee} extra for express delivery. It arrived {$days} days late. The {$item} itself works "
                . 'well, so I do not need a new one, but I paid for a service I did not get, and I would like that money back.',
            'replacement' => "My {$item} arrived on time, which I appreciated, but it does not switch on. I still want this {$item}, so I do "
                . 'not want my money back. Please send me one that works.',
            'date' => "I ordered a {$item} {$days} weeks ago and it has still not arrived. The tracking has not changed for days. I do not "
                . 'want to cancel, and I am not asking for money back; I just need to know when it will come.',
        };
        $answers = [
            'fee'         => "A refund of the R{$fee} delivery fee",
            'replacement' => "A working {$item} in place of this one",
            'date'        => 'To know when it will be delivered',
        ];

        return [
            'passage' => "Email from {$name}: " . $passage,
            'prompt'  => 'What does the customer mainly want?',
            'correct' => $answers[$want],
            'wrong'   => array_merge(array_values(array_diff($answers, [$answers[$want]])), ["A full refund for the {$item}"]),
            'params'  => ['want' => $want, 'fee' => $fee, 'item' => $item],
        ];
    }

    /** An overtime rule, applied with a catch. */
    private function qac2(): array
    {
        $name  = $this->pick(self::NAMES);
        $n     = $this->pick([3, 5, 7]);
        $hours = random_int(2, 5);
        $case  = $this->pick(['before', 'none', 'after']);

        $situation = match ($case) {
            'before' => "{$name}'s supervisor approved overtime before the shift started, and {$name} worked {$hours} extra hours.",
            'none'   => "{$name} worked {$hours} extra hours without asking anyone.",
            'after'  => "{$name} worked {$hours} extra hours, and the supervisor approved it the next morning.",
        };
        $options = [
            'yes'     => 'Yes, because it was approved before the shift',
            'manager' => "Only if the store manager signs it off within {$n} days",
            'asks'    => "Only if {$name} asks for it within {$n} days",
            'never'   => 'No, overtime without approval is never paid',
        ];
        $right = $case === 'before' ? 'yes' : 'manager';

        return [
            'passage' => 'Overtime must be approved by your shift supervisor before the shift starts. Overtime worked without that '
                . "approval is paid only if the store manager signs it off within {$n} days of the shift.",
            'prompt'  => $situation . " Will {$name} be paid for it?",
            'correct' => $options[$right],
            'wrong'   => array_values(array_diff($options, [$options[$right]])),
            'params'  => ['case' => $case],
        ];
    }

    /** Room booking rules with at most one broken. */
    private function qac3(): array
    {
        $room  = $this->pick(['training room', 'boardroom', 'small meeting room']);
        $hall  = $this->pick(['main hall', 'canteen']);
        $max   = random_int(2, 4);
        $ahead = random_int(2, 5);
        $size  = random_int(8, 15);
        $case  = $this->pick(['ok', 'size', 'late', 'long']);

        $people = $case === 'size' ? $size + random_int(1, 6) : random_int(3, $size);
        $hours  = $case === 'long' ? $max + random_int(1, 3) : random_int(1, $max);
        $days   = $case === 'late' ? random_int(1, $ahead - 1) : $ahead + random_int(0, 5);

        $options = [
            'ok'   => 'Yes, they can book it',
            'size' => "No, the group is too big for the {$room}",
            'late' => 'No, they are booking too late',
            'long' => 'No, the booking is too long',
        ];

        return [
            'passage' => "The {$room} can be booked for up to {$max} hours at a time. Bookings must be made at least {$ahead} days ahead. "
                . "Groups of more than {$size} people must use the {$hall} instead.",
            'prompt'  => "A team of {$people} wants the {$room} for {$hours} hours, and they are booking {$days} days ahead. Can they book it?",
            'correct' => $options[$case],
            'wrong'   => array_values(array_diff($options, [$options[$case]])),
            'params'  => ['max' => $max, 'ahead' => $ahead, 'size' => $size, 'people' => $people, 'hours' => $hours, 'days' => $days],
        ];
    }

    /** The tone of a customer's message. */
    private function qac4(): array
    {
        $name  = $this->pick(self::CUSTOMERS);
        $item  = $this->pick(self::ITEMS);
        $staff = $this->pick(self::NAMES);
        $tone  = $this->pick(['frustrated', 'pleased', 'confused', 'angry']);

        $passage = match ($tone) {
            'frustrated' => "Good morning. This is the third time I have asked about my {$item}. I understand things get busy, but I would "
                . "really appreciate a clear answer today. Kind regards, {$name}.",
            'pleased' => "Hi there, I just wanted to say that the {$item} arrived early and works perfectly. {$staff} was very helpful "
                . "on the phone. Thanks again, {$name}.",
            'confused' => "Hello, I received two emails about my {$item}, one saying it was sent and one saying it was cancelled. I am not "
                . "sure which is right. Could someone please explain? {$name}",
            'angry' => "This is unacceptable. My {$item} is broken AGAIN and nobody answers the phone. If I do not hear from you today, "
                . "I will take this further. {$name}",
        };
        $tones = [
            'frustrated' => 'Frustrated but still polite',
            'pleased'    => 'Pleased and thankful',
            'confused'   => 'Unsure and asking for help',
            'angry'      => 'Angry and making a threat',
        ];

        return [
            'passage' => $passage,
            'prompt'  => 'How does the writer feel?',
            'correct' => $tones[$tone],
            'wrong'   => array_values(array_diff($tones, [$tones[$tone]])),
            'params'  => ['tone' => $tone],
        ];
    }

    // -------------------------------------------- advanced: language

    private function qal1(): array
    {
        return $this->grammar('advanced');
    }

    private function qal2(): array
    {
        return $this->vocabulary('advanced');
    }

    private function qal3(): array
    {
        return $this->writing('advanced');
    }

    // --------------------------------------------- advanced: numbers

    private function qan1(): array
    {
        $from = random_int(2, 20) * 4000;
        $pct  = $this->pick([10, 20, 25, 40, 50, 60, 75]);
        $to   = intdiv($from * (100 + $pct), 100);

        return [
            'prompt'  => 'Monthly sales grew from ' . $this->rand($from) . ' to ' . $this->rand($to) . '. By what percentage did they grow?',
            'correct' => $pct . '%',
            'wrong'   => [(int) round(($to - $from) / $to * 100) . '%', (int) round($to / $from * 100) . '%', (int) round($from / $to * 100) . '%', ($pct + 5) . '%'],
            'params'  => ['from' => $from, 'to' => $to],
        ];
    }

    /** How many appointments with breaks fit into a morning. */
    private function qan2(): array
    {
        $len   = $this->pick([20, 25, 30, 40, 45]);
        $gap   = $this->pick([5, 10, 15]);
        $start = random_int(7, 9) * 60 + $this->pick([0, 30]);
        $end   = $start + random_int(3, 6) * 60 + $this->pick([0, 15, 30, 45]);
        $span  = $end - $start;
        $fit   = intdiv($span + $gap, $len + $gap);

        return [
            'prompt'  => "Appointments take {$len} minutes, with a {$gap} minute break after each one. The first starts at " . $this->time($start)
                . ' and the last must end by ' . $this->time($end) . '. How many appointments fit?',
            'correct' => (string) $fit,
            'wrong'   => [(string) intdiv($span, $len), (string) ($fit + 1), (string) ($fit - 1), (string) ($fit + 2)],
            'params'  => ['len' => $len, 'gap' => $gap, 'span' => $span],
        ];
    }

    private function qan3(): array
    {
        $task = $this->pick(['paint a house', 'pack an order', 'clear a site', 'tile a floor']);
        do {
            $w1    = random_int(2, 6);
            $h1    = random_int(4, 12);
            $total = $w1 * $h1;
            $other = array_values(array_filter(range(2, 12), static fn ($w) => $w !== $w1 && $total % $w === 0));
        } while ($other === []);
        $w2 = $this->pick($other);
        $h2 = intdiv($total, $w2);

        return [
            'prompt'  => "{$w1} workers take {$h1} hours to {$task}. How long would {$w2} workers take, working at the same pace?",
            'correct' => $this->hours($h2),
            'wrong'   => [$this->hours($h1 * $w2 / $w1), $this->hours($h1 + $w2 - $w1), $this->hours($h2 + 1), $this->hours($h2 * 2)],
            'params'  => ['w1' => $w1, 'h1' => $h1, 'w2' => $w2],
        ];
    }

    /** When to reorder so stock lasts until the delivery. */
    private function qan4(): array
    {
        $use    = random_int(4, 20);
        $lead   = random_int(2, 6);
        $backup = random_int(2, 6) * 5;
        $what   = $this->pick(['litres of milk', 'loaves of bread', 'bags of ice', 'kilograms of chicken']);

        return [
            'prompt'  => "A cafe uses {$use} {$what} a day. A delivery arrives {$lead} days after it is ordered, and the cafe always keeps {$backup} extra as backup. "
                . 'At what stock level should it place the next order?',
            'correct' => (string) ($use * $lead + $backup),
            'wrong'   => [(string) ($use * $lead), (string) ($use + $backup), (string) ($use * ($lead + 1) + $backup), (string) ($use * $lead - $backup)],
            'params'  => ['use' => $use, 'lead' => $lead, 'backup' => $backup],
        ];
    }

    /** Two quotes, one with a call out fee: which is cheaper and by how much. */
    private function qan5(): array
    {
        $trade = $this->pick(['Plumber', 'Electrician', 'Builder']);
        do {
            $fee   = random_int(3, 10) * 50;
            $rateA = random_int(30, 60) * 10;
            $rateB = $rateA + random_int(5, 20) * 10;
            $hours = random_int(2, 8);
            $a     = $fee + $rateA * $hours;
            $b     = $rateB * $hours;
        } while ($a === $b);
        $cheaper = $a < $b ? 'A' : 'B';
        $other   = $cheaper === 'A' ? 'B' : 'A';
        $diff    = abs($a - $b);

        return [
            'prompt'  => "{$trade} A charges a R{$fee} call out fee plus " . $this->rand($rateA) . " an hour. {$trade} B charges no call out fee but "
                . $this->rand($rateB) . " an hour. For a job of {$hours} hours, which is cheaper, and by how much?",
            'correct' => "{$trade} {$cheaper}, by " . $this->rand($diff),
            'wrong'   => ["{$trade} {$other}, by " . $this->rand($diff), "{$trade} {$cheaper}, by " . $this->rand(abs($rateB - $rateA) * $hours), "{$trade} {$cheaper}, by " . $this->rand($diff + $fee), 'They cost the same'],
            'params'  => ['fee' => $fee, 'rateA' => $rateA, 'rateB' => $rateB, 'hours' => $hours],
        ];
    }

    // ---------------------------------------------- language helpers

    private function grammar(string $level): array
    {
        $i    = random_int(0, count(self::GRAMMAR[$level]) - 1);
        $item = $this->fill(self::GRAMMAR[$level][$i]);

        return [
            'prompt'  => 'Which sentence is written correctly?',
            'correct' => $item[0],
            'wrong'   => array_slice($item, 1),
            'params'  => ['pool' => 'grammar', 'item' => $i],
        ];
    }

    private function vocabulary(string $level): array
    {
        $i    = random_int(0, count(self::VOCABULARY[$level]) - 1);
        $item = $this->fill(self::VOCABULARY[$level][$i]);

        return [
            'prompt'  => 'Read the sentence: "' . $item[0] . '" What does "' . $item[1] . '" mean here?',
            'correct' => $item[2],
            'wrong'   => array_slice($item, 3),
            'params'  => ['pool' => 'vocabulary', 'item' => $i],
        ];
    }

    private function writing(string $level): array
    {
        $i    = random_int(0, count(self::WRITING[$level]) - 1);
        $item = $this->fill(self::WRITING[$level][$i]);

        return [
            'prompt'  => $item[0],
            'correct' => $item[1],
            'wrong'   => array_slice($item, 2),
            'params'  => ['pool' => 'writing', 'item' => $i],
        ];
    }

    /**
     * Fill a pool item's placeholders, the same value everywhere it appears.
     *
     * @param list<string> $item
     *
     * @return list<string>
     */
    private function fill(array $item): array
    {
        $quote = random_int(20, 90) * 100;
        $map   = [
            '{name}'  => $this->pick(self::NAMES),
            '{cust}'  => $this->pick(self::CUSTOMERS),
            '{mgr}'   => $this->pick(self::NAMES),
            '{n}'     => (string) $this->pick([15, 20, 30, 40]),
            '{day}'   => $this->pick(self::WEEKDAYS),
            '{time}'  => $this->time(random_int(7, 9) * 60 + $this->pick([0, 30])),
            '{item}'  => $this->pick(self::ITEMS),
            '{date}'  => random_int(1, 28) . ' ' . $this->pick(['March', 'May', 'August', 'November']),
            '{quote}' => $this->num($quote),
            '{diff}'  => $this->num(random_int(3, 20) * 50),
        ];
        // {mgr} and {name} in one item are two different people.
        while ($map['{mgr}'] === $map['{name}']) {
            $map['{mgr}'] = $this->pick(self::NAMES);
        }

        return array_map(static fn (string $s) => strtr($s, $map), $item);
    }

    // ------------------------------------------------------------ helpers

    /**
     * The right answer and three distinct mistakes, shuffled. The mistakes
     * are taken in the order given, so the likeliest ones are used first.
     *
     * @param list<string> $wrong
     *
     * @return array{0:list<string>,1:int}|null null when fewer than three distinct mistakes remain
     */
    private function options(string $correct, array $wrong): ?array
    {
        $picked = [];
        foreach ($wrong as $w) {
            // A mistake that came out as nothing, a zero amount ("R0", "0 km")
            // or a negative rand (number_format's ASCII "-") is not a choice
            // anyone would weigh, so it is skipped.
            if ($w === '' || $w === $correct || in_array($w, $picked, true) || str_contains($w, '-') || preg_match('/^(R0$|0 \D|0$)/u', $w)) {
                continue;
            }
            $picked[] = $w;
            if (count($picked) === 3) {
                break;
            }
        }
        if (count($picked) < 3) {
            return null;
        }

        $options = array_merge([$correct], $picked);
        // Fisher Yates on random_int, so the right answer's place is not predictable.
        for ($i = 3; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$options[$i], $options[$j]] = [$options[$j], $options[$i]];
        }

        return [$options, (int) array_search($correct, $options, true)];
    }

    private function pick(array $from)
    {
        return $from[array_keys($from)[random_int(0, count($from) - 1)]];
    }

    /** $n different values from $from, in random order. */
    private function several(array $from, int $n): array
    {
        $from = array_values($from);
        for ($i = count($from) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$from[$i], $from[$j]] = [$from[$j], $from[$i]];
        }

        return array_slice($from, 0, $n);
    }

    /** An integer with the maths minus sign and space thousands. */
    private function num(int $n): string
    {
        return ($n < 0 ? '−' : '') . number_format(abs($n), 0, '.', ' ');
    }

    /** Rand, whole when whole, else to the cent: R1 000, R977.50. */
    private function rand(float|int $amount): string
    {
        $amount = round((float) $amount, 2);

        return 'R' . (fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0, '.', ' ')
            : number_format($amount, 2, '.', ' '));
    }

    /** Minutes since midnight as 24 hour time, wrapping past midnight. */
    private function time(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** "1 hour 35 minutes", "2 hours", "45 minutes". */
    private function duration(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        $parts = [];
        if ($h > 0) {
            $parts[] = $h . ($h === 1 ? ' hour' : ' hours');
        }
        if ($m > 0 || $h === 0) {
            $parts[] = $m . ($m === 1 ? ' minute' : ' minutes');
        }

        return implode(' ', $parts);
    }

    /** Whole hours, or hours and minutes when a mistake lands between. */
    private function hours(float|int $h): string
    {
        return $this->duration((int) round($h * 60));
    }
}
