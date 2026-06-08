# DonPesca Mar Forecast

Plugin de WordPress pensado para `donpesca.com`.

## Qué hace

- Permite abrir el informe a todo el mundo o dejarlo privado solo para emails autorizados.
- Permite consultar por coordenadas o por un listado de puertos de Euskadi y País Vasco francés.
- Cruza viento de `Météo-France`, `ECMWF` y `GFS`.
- Añade mar de `Open-Meteo Marine`, luna, sol y mareas estimadas.
- Calcula:
  - ventana sugerida
  - semáforo `VERDE / AMARILLO / ROJO`
  - probabilidad de acierto según convergencia de modelos y horizonte temporal
  - encaje de pesca más realista
  - familia o especie recomendada
  - lectura de marea `subiendo / bajando / rebase`
  - tendencia usable hasta 7 días

## Shortcode

```text
[donpesca_mar_report]
```

## Ajuste mínimo tras instalar

1. Copiar la carpeta `donpesca-mar-forecast` dentro de `wp-content/plugins/`.
2. Activar el plugin.
3. Ir a `Ajustes > DonPesca Mar Forecast`.
4. Decidir si el acceso será público o privado.
5. Si es privado, añadir los emails autorizados.
6. Insertar el shortcode en la página deseada.

## Decisiones técnicas de esta versión

- La confianza del parte se calcula con divergencia de viento y rachas entre `Météo-France`, `ECMWF` y `GFS`, más penalización por horizonte temporal.
- La marea usa `sea_level_height_msl` de Open-Meteo Marine como estimación; conviene mostrarlo siempre como apoyo y no como referencia náutica oficial.
- El criterio de salida es conservador: el plugin pondera el peor escenario de viento y rachas.
- Météo-France solo cubre corto plazo. Más allá pesan sobre todo `ECMWF` y `GFS`.
- La lógica de especies sigue reglas del Cantábrico: marea viva o muerta, subida, bajada, rebase y fase lunar importan distinto según familia.

## Siguiente mejora recomendable

- Añadir panel de administración con umbrales configurables por tipo de pesca o embarcación.
- Afinar reglas por especie concreta, temporada y modalidad.
- Integrar una fuente oficial/portuaria específica de mareas si dispones de API fiable para cada puerto.
