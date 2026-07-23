-- Repair UTF-8 symbols lost by an older seed import that used the client default
-- character set. The schema and PDO connection now use utf8mb4.

UPDATE products SET dimensions = '200×180×120 cm' WHERE id = 2 AND dimensions = '200?180?120 cm';
UPDATE products SET dimensions = '180×90×75 cm'   WHERE id = 3 AND dimensions = '180?90?75 cm';
UPDATE products SET dimensions = '160×140×76 cm'  WHERE id = 4 AND dimensions = '160?140?76 cm';
UPDATE products SET dimensions = '180×35×30 cm'   WHERE id = 5 AND dimensions = '180?35?30 cm';
UPDATE products SET dimensions = '240×60×240 cm'  WHERE id = 7 AND dimensions = '240?60?240 cm';
UPDATE products
SET description = 'King size bed (180×200) with upholstered headboard. Mattress not included.'
WHERE id = 2 AND description LIKE 'King size bed (180?200)%';

UPDATE services
SET description = 'Complete interior design: concept, 3D visuals, material selection and site supervision for homes, offices and cafés.'
WHERE id = 1 AND description LIKE '%caf?s.';
UPDATE services
SET description = 'Modern gypsum ceilings with hidden LED lighting. Price per m² including material and labor.'
WHERE id = 2 AND description LIKE '%m? including%';

UPDATE businesses
SET description = 'Interior design, gypsum work and painting for homes, offices and cafés. 8+ years of experience, portfolio available on request.'
WHERE id = 3 AND description LIKE '%caf?s.%';

UPDATE supplies SET name = 'MDF Board 18mm (122×244cm)' WHERE id = 1 AND name = 'MDF Board 18mm (122?244cm)';
UPDATE supplies SET name = 'Melamine MDF 16mm — White'  WHERE id = 2 AND name = 'Melamine MDF 16mm ? White';
UPDATE supplies SET name = 'Wood Lacquer 4L — Clear'    WHERE id = 5 AND name = 'Wood Lacquer 4L ? Clear';
UPDATE supplies SET size = '122×244 cm' WHERE id IN (1, 2, 3) AND size = '122?244 cm';
