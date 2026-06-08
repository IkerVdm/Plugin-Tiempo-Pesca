# DonPesca Mar Forecast

Plugin de WordPress pensado para `donpesca.com`.

## Qué hace

- Restringe el acceso a usuarios logados cuyo email esté en una lista blanca.
- Permite consultar por coordenadas o por un listado de puertos de Euskadi y País Vasco francés.
- Cruza viento de `Météo-France`, `ECMWF` y `GFS`.
- Añade mar de `Open-Meteo Marine`, luna, sol y mareas estimadas.
- Calcula:
  - ventana sugerida
  - semáforo `VERDE / AMARILLO / ROJO`
  - probabilidad de acierto según convergencia de modelos y horizonte temporal
  - encaje orientativo para pesca

## Shortcode

```text
[donpesca_mar_report]
```

## Ajuste mínimo tras instalar

1. Copiar la carpeta `donpesca-mar-forecast` dentro de `wp-content/plugins/`.
2. Activar el plugin.
3. Ir a `Ajustes > DonPesca Mar Forecast`.
4. Añadir los emails autorizados.
5. Insertar el shortcode en una página privada o de socios.

## Decisiones técnicas de esta versión

- La confianza del parte se calcula con divergencia de viento y rachas entre `Météo-France`, `ECMWF` y `GFS`, más penalización por horizonte temporal.
- La marea usa `sea_level_height_msl` de Open-Meteo Marine como estimación; conviene mostrarlo siempre como apoyo y no como referencia náutica oficial.
- El criterio de salida es conservador: el plugin pondera el peor escenario de viento y rachas.

## Siguiente mejora recomendable

- Añadir panel de administración con umbrales configurables por tipo de pesca o embarcación.
- Afinar una tabla propia por especie y modalidad.
- Integrar una fuente oficial/portuaria específica de mareas si dispones de API fiable para cada puerto.
