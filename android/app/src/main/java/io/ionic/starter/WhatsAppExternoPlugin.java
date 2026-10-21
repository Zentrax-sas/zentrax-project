package io.ionic.starter;

import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.net.Uri;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

@CapacitorPlugin(name = "WhatsAppExterno")
public class WhatsAppExternoPlugin extends Plugin {
    @PluginMethod
    public void abrir(PluginCall call) {
        String nativa = call.getString("nativa", "");
        String web = call.getString("web", "");
        Uri uri = Uri.parse(nativa);
        Uri fallback = Uri.parse(web);
        if (!"whatsapp".equals(uri.getScheme()) || !"send".equals(uri.getHost())
                || !"https".equals(fallback.getScheme()) || !"wa.me".equals(fallback.getHost())) {
            call.reject("Enlace no válido");
            return;
        }
        getActivity().runOnUiThread(() -> {
            try {
                // ACTION_VIEW only prepares the draft; WhatsApp controls sending/cancellation.
                try {
                    getActivity().startActivity(new Intent(Intent.ACTION_VIEW, uri));
                } catch (ActivityNotFoundException missingWhatsApp) {
                    getActivity().startActivity(new Intent(Intent.ACTION_VIEW, fallback));
                }
                call.resolve();
            } catch (Exception error) {
                call.reject("No se pudo abrir WhatsApp", error);
            }
        });
    }
}
