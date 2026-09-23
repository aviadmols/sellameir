# sellameir.ussl.co: שגיאות בלוג ומהירות, מה צריך לעשות בשרת

נמדד ב-23.9.2026. כל עמוד באתר לוקח כ-2.2 שניות רק עד שהשרת מתחיל להחזיר תשובה (TTFB). גם בקשה ריקה ל-`admin-ajax.php` לוקחת 2.5 שניות, כלומר הזמן הולך על טעינת וורדפרס עם כל התוספים ולא על ציור העמוד. אין caching של עמודים, ולקבצים הסטטיים אין כותרות cache.

## 1. שגיאות "access forbidden by rule" (ללא פעולה נדרשת)

סורק אוטומטי (35.240.100.200) מחפש קבצי סודות: `.env`, `.ssh/id_rsa`, `.git`, `.terraform` וכו'. nginx חוסם אותו כמו שצריך, וזו ההודעה שנרשמת. אין כאן תקלה.
רשות: fail2ban על השורה הזו בלוג, כדי לחסום IP שמייצר הרבה כאלה.

## 2. שגיאות "index.php is not found" על `/product/...?add-to-cart=N`

כלל ב-nginx מחזיר 404 לכל בקשה עם `add-to-cart=<ערך>` תחת `/product/` ו-`/cart/`, עוד לפני שוורדפרס רץ (התשובה חוזרת תוך כ-70ms). הכתובות `/shop/?add-to-cart=N`, `/?add-to-cart=N` ו-`/checkout/?add-to-cart=N` עובדות.

- בערכת העיצוב: קישורי "הוספה לסל" נבנים עכשיו על עמוד החנות (`inc/sella-add-to-cart-links.php`), ולכן האתר לא מייצר יותר קישורים שבורים.
- בשרת: כתובות ישנות שכבר נשמרו (למשל אצל הזחלן של פייסבוק, 173.252.x.x, שמגיע דרך הפיקסל) עדיין יקבלו 404. צריך למצוא ב-nginx את הכלל שבודק `add-to-cart` (בלי תלות באותיות גדולות וקטנות) ולוודא שה-location שלו מסתיים ב-`try_files $uri $uri/ /index.php?$args;`. אם הכלל נועד לחסום בוטים, עדיף שיחזיר 301 ל-`/shop/?$args` ולא 404.

## 3. כותרות cache לקבצים סטטיים

תמונות, CSS, JS ופונטים מוגשים בלי `Cache-Control` או `Expires`, ולכן הדפדפן בודק מחדש כ-170 קבצים בכל צפייה בעמוד. ל-server block של האתר:

```nginx
location ~* \.(?:css|js|mjs|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp|avif|ico)$ {
    expires 30d;
    add_header Cache-Control "public, max-age=2592000";
    access_log off;
    try_files $uri =404;
}
```

(קבצי CSS ו-JS של וורדפרס כוללים `?ver=` בכתובת, כך ששינויים ייטענו מיד גם עם cache.)

## 4. Page cache (השיפור הגדול ביותר)

cache של עמודים מוריד את ה-TTFB מ-2.2 שניות לעשרות מילישניות אצל גולשים אנונימיים. אם השרת תומך ב-nginx `fastcgi_cache`, זה העדיף. אחרת, תוסף **WP Super Cache**:

1. Settings → WP Super Cache → Caching On, מצב Simple.
2. Advanced: "Don't cache pages for known users", ובלי "Cache HTTP headers".
3. ב-Rejected Cookies להוסיף: `woocommerce_items_in_cart`, `wp_woocommerce_session_`, `woocommerce_cart_hash`. מי שיש לו מוצרים בסל יקבל עמוד חי עם הסל הנכון.
4. ב-Rejected URL Strings להוסיף: `/cart/`, `/checkout/`, `/my-account/`, `add-to-cart`, `wc-ajax`.
5. Expiry Time: עד 10 שעות (טפסים של Elementor משתמשים ב-nonce שפג אחרי 12 שעות).

אחרי ההפעלה: לבדוק הוספה לסל, מונה הסל בכותרת, הצ'קאאוט והפופאפ של המבצעים, גם כאורח וגם כמשתמש מחובר.

## 5. Object cache (Redis)

אם החבילה בשרת כוללת Redis: להתקין את התוסף **Redis Object Cache** וללחוץ Enable. זה מקצר את טעינת וורדפרס גם בעמודים שלא נכנסים ל-cache (סל, צ'קאאוט, admin).

## 6. בדיקת תוספים

פעילים כרגע: Jetpack, Yoast, WPCode, CPT UI, PayPlus, UserWay, Elementor AI, hero-ai, smart-cart, sela-meir-tracking ועוד (865 נתיבי REST). להתקין זמנית את **Query Monitor**, לפתוח עמוד כמנהל ולבדוק בלשונית "Queries by Component" ובזמני הטעינה מי התוסף הכבד. Jetpack כבד במיוחד, וכדאי לכבות אותו אם לא משתמשים בו.
