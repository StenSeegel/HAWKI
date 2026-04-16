const fs = require('fs');

class TranslateApp {
    constructor() {
        this.sourceSentences = ["Dies ist Satz 1. ", "Dies ist Satz 2."];
        this.targetSentences = ["Das ist Satz 1. ", "Das ist Satz 2."];
    }

    testLogic() {
        const index = 0;
        const sourceS = this.sourceSentences[index] || '';
        const match = sourceS.match(/\s+$/);
        const trailing = match ? match[0] : '';
        
        let correctedText = "Das ist ein korrigierter Satz 1.";
        
        let result = (trailing && !correctedText.endsWith(trailing)) ? correctedText.trimEnd() + trailing : correctedText;
        console.log("Trailing length:", trailing.length, "Result:", result, "Result length:", result.length);
    }
}
new TranslateApp().testLogic();
