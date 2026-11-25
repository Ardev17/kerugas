package ao.kerugas.app;

import android.os.Bundle;
import androidx.activity.EdgeToEdge;
import com.getcapacitor.BridgeActivity;

/**
 * Atividade única do app KeruGás,o Capacitor carrega o frontend React
 * num WebView dentro dela.
 *
 * EdgeToEdge.enable(): recomendado pela documentação do Capacitor 8
 * (SystemBars) sempre que `insetsHandling` não está em `disable`. Deixa o
 * app desenhar por baixo da barra de estado e da barra de gestos em
 * TODAS as versões do Android (no 15+ o sistema já obriga a isto), e o
 * CSS trata das margens seguras (--safe-area-inset-*, globals.css).
 * Assim o comportamento é igual num Android 10 e num Android 16.
 */
public class MainActivity extends BridgeActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        EdgeToEdge.enable(this);
        super.onCreate(savedInstanceState);
    }
}
