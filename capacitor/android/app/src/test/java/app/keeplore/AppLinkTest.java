package app.keeplore;

import java.nio.charset.StandardCharsets;
import java.util.Base64;

/**
 * Process adapter for the PHPUnit seam tests. Five args are base64, or "-"
 * for null: scheme, host, path, query, fragment. Prints the loaded URL as
 * base64, or "-" when the App Link must not load.
 */
public final class AppLinkTest {

    public static void main(String[] args) {
        if (args.length != 5) {
            System.err.println("expected 5 args");
            System.exit(2);
        }
        String loaded = AppLink.urlToLoad(part(args[0]), part(args[1]), part(args[2]), part(args[3]), part(args[4]));
        if (loaded == null) {
            System.out.print("-");
            return;
        }
        System.out.print(Base64.getEncoder().encodeToString(loaded.getBytes(StandardCharsets.UTF_8)));
    }

    private static String part(String arg) {
        if ("-".equals(arg)) {
            return null;
        }
        return new String(Base64.getDecoder().decode(arg), StandardCharsets.UTF_8);
    }
}
