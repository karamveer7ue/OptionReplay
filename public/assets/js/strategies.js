/* Ready-made strategy templates. offset = strikes from ATM (positive = higher strike). exp = expiry index (0 = selected, 1 = next). */
window.STRATEGY_TEMPLATES = [
  // Bullish
  { name: 'Long Call', group: 'Bullish', desc: 'Buy ATM call', legs: [{ side: 'B', kind: 'CE', offset: 0 }] },
  { name: 'Short Put', group: 'Bullish', desc: 'Sell ATM put', legs: [{ side: 'S', kind: 'PE', offset: 0 }] },
  { name: 'Bull Call Spread', group: 'Bullish', desc: 'Buy ATM call, sell OTM call', legs: [{ side: 'B', kind: 'CE', offset: 0 }, { side: 'S', kind: 'CE', offset: 4 }] },
  { name: 'Bull Put Spread', group: 'Bullish', desc: 'Sell ATM put, buy OTM put', legs: [{ side: 'S', kind: 'PE', offset: 0 }, { side: 'B', kind: 'PE', offset: -4 }] },
  { name: 'Call Ratio Back Spread', group: 'Bullish', desc: 'Sell 1 ATM call, buy 2 OTM calls', legs: [{ side: 'S', kind: 'CE', offset: 0 }, { side: 'B', kind: 'CE', offset: 3, lots: 2 }] },
  { name: 'Synthetic Long', group: 'Bullish', desc: 'Buy ATM call, sell ATM put', legs: [{ side: 'B', kind: 'CE', offset: 0 }, { side: 'S', kind: 'PE', offset: 0 }] },
  { name: 'Risk Reversal', group: 'Bullish', desc: 'Buy OTM call, sell OTM put', legs: [{ side: 'B', kind: 'CE', offset: 3 }, { side: 'S', kind: 'PE', offset: -3 }] },
  // Bearish
  { name: 'Long Put', group: 'Bearish', desc: 'Buy ATM put', legs: [{ side: 'B', kind: 'PE', offset: 0 }] },
  { name: 'Short Call', group: 'Bearish', desc: 'Sell ATM call', legs: [{ side: 'S', kind: 'CE', offset: 0 }] },
  { name: 'Bear Put Spread', group: 'Bearish', desc: 'Buy ATM put, sell OTM put', legs: [{ side: 'B', kind: 'PE', offset: 0 }, { side: 'S', kind: 'PE', offset: -4 }] },
  { name: 'Bear Call Spread', group: 'Bearish', desc: 'Sell ATM call, buy OTM call', legs: [{ side: 'S', kind: 'CE', offset: 0 }, { side: 'B', kind: 'CE', offset: 4 }] },
  { name: 'Put Ratio Back Spread', group: 'Bearish', desc: 'Sell 1 ATM put, buy 2 OTM puts', legs: [{ side: 'S', kind: 'PE', offset: 0 }, { side: 'B', kind: 'PE', offset: -3, lots: 2 }] },
  { name: 'Synthetic Short', group: 'Bearish', desc: 'Buy ATM put, sell ATM call', legs: [{ side: 'B', kind: 'PE', offset: 0 }, { side: 'S', kind: 'CE', offset: 0 }] },
  // Neutral
  { name: 'Short Straddle', group: 'Neutral', desc: 'Sell ATM call and put', legs: [{ side: 'S', kind: 'CE', offset: 0 }, { side: 'S', kind: 'PE', offset: 0 }] },
  { name: 'Short Strangle', group: 'Neutral', desc: 'Sell OTM call and put', legs: [{ side: 'S', kind: 'CE', offset: 4 }, { side: 'S', kind: 'PE', offset: -4 }] },
  { name: 'Short Iron Condor', group: 'Neutral', desc: 'Strangle with protective wings', legs: [{ side: 'S', kind: 'CE', offset: 3 }, { side: 'B', kind: 'CE', offset: 6 }, { side: 'S', kind: 'PE', offset: -3 }, { side: 'B', kind: 'PE', offset: -6 }] },
  { name: 'Short Iron Butterfly', group: 'Neutral', desc: 'Straddle with protective wings', legs: [{ side: 'S', kind: 'CE', offset: 0 }, { side: 'B', kind: 'CE', offset: 4 }, { side: 'S', kind: 'PE', offset: 0 }, { side: 'B', kind: 'PE', offset: -4 }] },
  { name: 'Call Butterfly', group: 'Neutral', desc: 'Buy 1 ITM, sell 2 ATM, buy 1 OTM call', legs: [{ side: 'B', kind: 'CE', offset: -3 }, { side: 'S', kind: 'CE', offset: 0, lots: 2 }, { side: 'B', kind: 'CE', offset: 3 }] },
  { name: 'Put Butterfly', group: 'Neutral', desc: 'Buy 1 OTM, sell 2 ATM, buy 1 ITM put', legs: [{ side: 'B', kind: 'PE', offset: -3 }, { side: 'S', kind: 'PE', offset: 0, lots: 2 }, { side: 'B', kind: 'PE', offset: 3 }] },
  { name: 'Batman', group: 'Neutral', desc: 'Two butterflies either side of spot', legs: [{ side: 'B', kind: 'CE', offset: 1 }, { side: 'S', kind: 'CE', offset: 4, lots: 2 }, { side: 'B', kind: 'CE', offset: 7 }, { side: 'B', kind: 'PE', offset: -1 }, { side: 'S', kind: 'PE', offset: -4, lots: 2 }, { side: 'B', kind: 'PE', offset: -7 }] },
  { name: 'Double Plateau', group: 'Neutral', desc: 'Two condors either side of spot', legs: [{ side: 'B', kind: 'CE', offset: 1 }, { side: 'S', kind: 'CE', offset: 3 }, { side: 'S', kind: 'CE', offset: 5 }, { side: 'B', kind: 'CE', offset: 7 }, { side: 'B', kind: 'PE', offset: -1 }, { side: 'S', kind: 'PE', offset: -3 }, { side: 'S', kind: 'PE', offset: -5 }, { side: 'B', kind: 'PE', offset: -7 }] },
  { name: 'Jade Lizard', group: 'Neutral', desc: 'Short put + bear call spread, no upside risk', legs: [{ side: 'S', kind: 'PE', offset: -3 }, { side: 'S', kind: 'CE', offset: 3 }, { side: 'B', kind: 'CE', offset: 5 }] },
  { name: 'Reverse Jade Lizard', group: 'Neutral', desc: 'Short call + bull put spread', legs: [{ side: 'S', kind: 'CE', offset: 3 }, { side: 'S', kind: 'PE', offset: -3 }, { side: 'B', kind: 'PE', offset: -5 }] },
  { name: 'Calendar Spread', group: 'Neutral', desc: 'Sell near-expiry ATM call, buy next-expiry ATM call', legs: [{ side: 'S', kind: 'CE', offset: 0, exp: 0 }, { side: 'B', kind: 'CE', offset: 0, exp: 1 }] },
  // Other (volatility)
  { name: 'Long Straddle', group: 'Other', desc: 'Buy ATM call and put', legs: [{ side: 'B', kind: 'CE', offset: 0 }, { side: 'B', kind: 'PE', offset: 0 }] },
  { name: 'Long Strangle', group: 'Other', desc: 'Buy OTM call and put', legs: [{ side: 'B', kind: 'CE', offset: 4 }, { side: 'B', kind: 'PE', offset: -4 }] },
  { name: 'Long Iron Condor', group: 'Other', desc: 'Buy inner strangle, sell outer wings', legs: [{ side: 'B', kind: 'CE', offset: 3 }, { side: 'S', kind: 'CE', offset: 6 }, { side: 'B', kind: 'PE', offset: -3 }, { side: 'S', kind: 'PE', offset: -6 }] },
  { name: 'Strip', group: 'Other', desc: 'Buy 1 ATM call, 2 ATM puts', legs: [{ side: 'B', kind: 'CE', offset: 0 }, { side: 'B', kind: 'PE', offset: 0, lots: 2 }] },
  { name: 'Strap', group: 'Other', desc: 'Buy 2 ATM calls, 1 ATM put', legs: [{ side: 'B', kind: 'CE', offset: 0, lots: 2 }, { side: 'B', kind: 'PE', offset: 0 }] },
];
window.STRATEGY_GROUPS = ['Bullish', 'Bearish', 'Neutral', 'Other'];
