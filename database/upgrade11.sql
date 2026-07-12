-- Latin<->Amharic search synonym dictionary (PLAN.md → Marketplace fundamentals →
-- Localization → transliteration-aware search). Curated marketplace vocabulary, not a
-- generated character-level transliteration table — see app/search_synonyms.php for why.
-- Seed covers common furniture/marketplace terms; admins extend it under
-- Admin → Categories → Search Synonyms as real customer query patterns emerge.

CREATE TABLE IF NOT EXISTS search_synonyms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    latin_term VARCHAR(100) NOT NULL,
    amharic_term VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pair (latin_term, amharic_term)
);

INSERT IGNORE INTO search_synonyms (latin_term, amharic_term) VALUES
    ('wenber', 'ወንበር'),
    ('wonber', 'ወንበር'),
    ('terepeza', 'ጠረጴዛ'),
    ('tereqeza', 'ጠረጴዛ'),
    ('alga', 'አልጋ'),
    ('bet eqa', 'ቤት እቃ'),
    ('eqa', 'እቃ'),
    ('enchet', 'እንጨት'),
    ('bret', 'ብረት'),
    ('qoda', 'ቆዳ'),
    ('cherk', 'ጨርቅ'),
    ('mesetawet', 'መስታወት'),
    ('birdilbs', 'ብርድ ልብስ'),
    ('tiras', 'ትራስ'),
    ('mintaf', 'ምንጣፍ'),
    ('megareja', 'መጋረጃ'),
    ('tikur', 'ጥቁር'),
    ('nech', 'ነጭ'),
    ('qey', 'ቀይ'),
    ('semayawi', 'ሰማያዊ'),
    ('bunama', 'ቡናማ'),
    ('arenguade', 'አረንጓዴ'),
    ('bicha', 'ቢጫ'),
    ('gracha', 'ግራጫ'),
    ('megnita bet', 'መኝታ ቤት'),
    ('salon', 'ሳሎን'),
    ('kushina', 'ኩሽና'),
    ('biro', 'ቢሮ'),
    ('addis', 'አዲስ'),
    ('yagelegele', 'ያገለገለ'),
    ('rikash', 'ርካሽ'),
    ('waga', 'ዋጋ'),
    ('melakiya', 'መላኪያ'),
    ('tekela', 'ተከላ'),
    ('tigena', 'ጥገና'),
    ('suq', 'ሱቅ');
