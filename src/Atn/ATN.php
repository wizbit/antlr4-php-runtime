<?php
/* Copyright (c) 2012-2017 The ANTLR Project. All rights reserved.
 * Use of this file is governed by the BSD 3-clause license that
 * can be found in the LICENSE.txt file in the project root.
 */

namespace Antlr4\Atn;

use Antlr4\Atn\Actions\LexerAction;
use Antlr4\Atn\States\ATNState;
use Antlr4\Atn\States\DecisionState;
use Antlr4\Atn\States\RuleStartState;
use Antlr4\Atn\States\RuleStopState;
use Antlr4\Atn\States\TokensStartState;
use Antlr4\LL1Analyzer;
use Antlr4\IntervalSet;
use Antlr4\ParserRuleContext;
use Antlr4\RuleContext;
use Antlr4\Token;

class ATN
{
    const GRAMMAR_TYPE_LEXER = 0;
    const GRAMMAR_TYPE_PARSER = 1;

    const INVALID_ALT_NUMBER = 0;

    /**
     * @var int
     */
    public $grammarType;

    /**
     * @var int
     */
    public $maxTokenType;

    /**
     * @var ATNState[]
     */
    public $states;

    /**
     * @var DecisionState[]
     */
    public $decisionToState;

    /**
     * @var RuleStartState[]
     */
    public $ruleToStartState;

    /**
     * @var RuleStopState[]
     */
    public $ruleToStopState;

    /**
     * @var array
     */
    public $modeNameToStartState;

    /**
     * @var int[]
     */
    public $ruleToTokenType;

    /**
     * @var LexerAction[]
     */
    public $lexerActions;

    /**
     * @var TokensStartState[]
     */
    public $modeToStartState;

    /**
     * ATN constructor.
     * @param int $grammarType ATNType
     * @param int $maxTokenType
     */
    function __construct(int $grammarType, int $maxTokenType)
    {
        // Used for runtime deserialization of ATNs from strings
        // The type of the ATN.
        $this->grammarType = $grammarType;

        // The maximum value for any symbol recognized by a transition in the ATN.
        $this->maxTokenType = $maxTokenType;
        $this->states = [];

        // Each subrule/rule is a decision point and we must track them so we
        //  can go back later and build DFA predictors for them.  This includes
        //  all the rules, subrules, optional blocks, ()+, ()* etc...
        $this->decisionToState = [];

        // Maps from rule index to starting state number.
        $this->ruleToStartState = [];

        // Maps from rule index to stop state number.
        $this->ruleToStopState = null;

        $this->modeNameToStartState = [];

        // For lexer ATNs, this maps the rule index to the resulting token type.
        // For parser ATNs, this maps the rule index to the generated bypass token
        // type if the
        // {@link ATNDeserializationOptions//isGenerateRuleBypassTransitions}
        // deserialization option was specified; otherwise, this is {@code null}.
        $this->ruleToTokenType = null;

        // For lexer ATNs, this is an array of {@link LexerAction} objects which may
        // be referenced by action transitions in the ATN.
        $this->lexerActions = null;

        $this->modeToStartState = [];
    }

    // Compute the set of valid tokens that can occur starting in state {@code s}.
    //  If {@code ctx} is null, the set of tokens will not include what can follow
    //  the rule surrounding {@code s}. In other words, the set will be
    //  restricted to tokens reachable staying within {@code s}'s rule.
    private function nextTokensInContext(ATNState $s, ?RuleContext $ctx) : IntervalSet
    {
        $anal = new LL1Analyzer($this);
        return $anal->LOOK($s, null, $ctx);
    }

    // Compute the set of valid tokens that can occur starting in {@code s} and
    // staying in same rule. {@link Token//EPSILON} is in set if we reach end of
    // rule.
    private function nextTokensNoContext(ATNState $s) : IntervalSet
    {
        if ($s->nextTokenWithinRule !== null )
        {
            return $s->nextTokenWithinRule;
        }
        $s->nextTokenWithinRule = $this->nextTokensInContext($s, null);
        $s->nextTokenWithinRule->readOnly = true;
        return $s->nextTokenWithinRule;
    }

    function nextTokens(ATNState $s, RuleContext $ctx=null) : IntervalSet
    {
        if (!$ctx) return $this->nextTokensNoContext($s);
        return $this->nextTokensInContext($s, $ctx);
    }

    function addState(ATNState $state) : void
    {
        if ($state !== null)
        {
            $state->atn = $this;
            $state->stateNumber = count($this->states);
        }
        $this->states[] = $state;
    }

    function removeState(ATNState $state) : void
    {
        $this->states[$state->stateNumber] = null;// just free mem, don't shift states in list
    }

    function defineDecisionState(DecisionState $s) : int
    {
        $this->decisionToState[] = $s;
        $s->decision = count($this->decisionToState) - 1;
        return $s->decision;
    }

    function getDecisionState(int $decision) : DecisionState
    {
        if (!count($this->decisionToState)) return null;
        return $this->decisionToState[$decision];
    }

    function getNumberOfDecisions() : int { return count($this->decisionToState); }

    // Computes the set of input symbols which could follow ATN state number
    // {@code stateNumber} in the specified full {@code context}. This method
    // considers the complete parser context, but does not evaluate semantic
    // predicates (i.e. all predicates encountered during the calculation are
    // assumed true). If a path in the ATN exists from the starting state to the
    // {@link RuleStopState} of the outermost context without matching any
    // symbols, {@link Token//EOF} is added to the returned set.
    //
    // <p>If {@code context} is {@code null}, it is treated as {@link ParserRuleContext//EMPTY}.</p>
    //
    // @param stateNumber the ATN state number
    // @param context the full parse context
    // @return The set of potentially valid input symbols which could follow the specified state in the specified context.
    // @throws IllegalArgumentException if the ATN does not contain a state with number {@code stateNumber}
    function getExpectedTokens(int $stateNumber, ParserRuleContext $ctx) : IntervalSet
    {
        if ($stateNumber < 0 || $stateNumber >= count($this->states))
        {
            throw new \RuntimeException("Invalid state number.");
        }

        $s = $this->states[$stateNumber];
        $following = $this->nextTokens($s);
        if (!$following->contains(Token::EPSILON))
        {
            return $following;
        }
        $expected = new IntervalSet();
        $expected->addSet($following);
        $expected->removeOne(Token::EPSILON);
        while ($ctx !== null && $ctx->invokingState >= 0 && $following->contains(Token::EPSILON))
        {
            $invokingState = $this->states[$ctx->invokingState];
            $rt = $invokingState->transitions[0];
            $following = $this->nextTokens($rt->followState);
            $expected->addSet($following);
            $expected->removeOne(Token::EPSILON);
            $ctx = $ctx->getParent();
        }
        if ($following->contains(Token::EPSILON))
        {
            $expected->addOne(Token::EOF);
        }
        return $expected;
    }
}