export const formatDate = (date: Date | string, locale: string): string => {
  const normalizedLocale = locale.replace("_", "-");
  const deliveryDateObject = new Date(date);

  const deliveryDateFormatted = deliveryDateObject.toLocaleDateString(normalizedLocale, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'long',
    timeZone: "UTC"
  });

  return deliveryDateFormatted.charAt(0).toUpperCase() + deliveryDateFormatted.slice(1);
}
