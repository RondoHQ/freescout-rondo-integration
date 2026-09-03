<?php

use Modules\RondoIntegration\Services\SportlinkRelationCodeExtractor;
use PHPUnit\Framework\TestCase;

class SportlinkRelationCodeExtractorTest extends TestCase
{
    private $extractor;

    protected function setUp(): void
    {
        $this->extractor = new SportlinkRelationCodeExtractor();
    }

    public function testExtractsTheOnlyValidatedRelationCodeFromTheSportlinkTable()
    {
        $html = '<table><tr><td style="width:250px"><b>Relatiecode :</b></td>'
            . "<td style=\"width:370px\"> &nbsp; LXCX82K \n </td></tr></table>";

        $this->assertSame(
            'LXCX82K',
            $this->extractor->extract('Overschrijvingsverzoek', 'NO-REPLY@SPORTLINKSERVICES.NL', $html)
        );
    }

    public function testIgnoresOtherSendersAndSubjects()
    {
        $html = '<table><tr><td>Relatiecode :</td><td>LXCX82K</td></tr></table>';

        $this->assertNull($this->extractor->extract('Ander onderwerp', SportlinkRelationCodeExtractor::SENDER, $html));
        $this->assertNull($this->extractor->extract(SportlinkRelationCodeExtractor::SUBJECT, 'attacker@example.test', $html));
    }

    public function testFailsClosedForInvalidOrConflictingRelationCodes()
    {
        $invalid = '<table><tr><td>Relatiecode :</td><td>../bad</td></tr></table>';
        $conflicting = '<table><tr><td>Relatiecode :</td><td>LXCX82K</td></tr>'
            . '<tr><td>Relatiecode :</td><td>SQJG27J</td></tr></table>';

        $this->assertNull($this->extractor->extract(SportlinkRelationCodeExtractor::SUBJECT, SportlinkRelationCodeExtractor::SENDER, $invalid));
        $this->assertNull($this->extractor->extract(SportlinkRelationCodeExtractor::SUBJECT, SportlinkRelationCodeExtractor::SENDER, $conflicting));
    }
}
