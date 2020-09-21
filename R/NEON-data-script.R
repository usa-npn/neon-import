# Initialize NEON Package
library(neonUtilities)
source("../config.R")

# Download all phenology files
dat <- loadByProduct(dpID= "DP1.10055.001",

                        package = "basic",

                        check.size = FALSE,

                        token = neon_api_key)

#Output the files

write.csv(dat$phe_perindividual, "../data/phe_perindividual.csv")
write.csv(dat$phe_perindividualperyear, "../data/phe_perindividualperyear.csv")
write.csv(dat$phe_statusintensity, "../data/phe_statusintensity.csv")
