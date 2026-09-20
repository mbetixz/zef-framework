<?php

declare(strict_types=1);

/**
 * TEMPORARY FILE - deliberately unformatted.
 *
 * This file exists only so the "Mago Quality Gate" required check can be proven
 * BINDING: it makes Mago report real findings, so the gate job must fail and the
 * pull request must be blocked from merging. It is deleted again in the next
 * commit on the same branch, which also returns the gate to green.
 *
 * The syntax below is valid PHP: it is the STYLE (not a parse error) that Mago
 * flags, which is what a static-quality gate is supposed to catch.
 */
function zef_gate_probe_calculate_totals( $items ,$taxRate=0.0 )
{
    $total=0;
        foreach($items as $item){
            $total = $total+$item['price']*$item['qty'];
        }
    if($taxRate>0){$total=$total*(1+$taxRate);}
            return round( $total , 2 );
}
